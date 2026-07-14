<?php
/**
 * Functional test suite for the Jackpot Sync plugin services.
 *
 * Runs against the in-memory WordPress stubs (wp-stubs.php) so the full
 * create/update/skip/validate/format/image pipeline is exercised without a
 * real WordPress install.
 *
 * Usage: php wordpress-plugin/tests/run-tests.php
 *
 * @package JackpotSync\Tests
 */

require __DIR__ . '/wp-stubs.php';

$base = __DIR__ . '/../jackpot-sync/includes/';
require $base . 'class-jackpot-settings.php';
require $base . 'class-jackpot-logger.php';
require $base . 'class-jackpot-message-parser.php';
require $base . 'class-jackpot-prize-formatter.php';
require $base . 'class-jackpot-image-resolver.php';
require $base . 'class-jackpot-repository.php';
require $base . 'class-jackpot-cache.php';
require $base . 'class-jackpot-result.php';
require $base . 'class-jackpot-creator.php';
require $base . 'class-jackpot-updater.php';
require $base . 'class-jackpot-processor.php';

$passed = 0;
$failed = 0;

/**
 * Assertion helper.
 */
function check($name, $condition) {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   - {$name}\n";
    } else {
        $failed++;
        echo "  FAIL - {$name}\n";
    }
}

/**
 * Build a fresh processor for each scenario.
 */
function make_processor() {
    $repo      = new Jackpot_Sync_Repository();
    $resolver  = new Jackpot_Sync_Image_Resolver();
    $formatter = new Jackpot_Sync_Prize_Formatter();
    $cache     = new Jackpot_Sync_Cache();
    $creator   = new Jackpot_Sync_Creator($repo, $resolver);
    $updater   = new Jackpot_Sync_Updater($repo, $formatter, $cache);
    return new Jackpot_Sync_Processor($creator, $updater);
}

echo "== Message parser ==\n";
$p = Jackpot_Sync_Message_Parser::parse_line('JPCONFIG;O136;2;11;Huff n Puff Mystery LVL2;;IFCO');
check('parse_line JPCONFIG type', !is_wp_error($p) && $p['type'] === 'JPCONFIG');
check('parse_line JPCONFIG jpId', !is_wp_error($p) && $p['jpId'] === 'O136');
check('parse_line JPCONFIG jpName', !is_wp_error($p) && $p['jpName'] === 'Huff n Puff Mystery LVL2');
check('parse_line JPCONFIG casId', !is_wp_error($p) && $p['casId'] === 'IFCO');

$u = Jackpot_Sync_Message_Parser::parse_line('JPUPDATE;O136;2;0;217;53467;867;0;IFCO');
check('parse_line JPUPDATE jpValue', !is_wp_error($u) && $u['jpValue'] === '53467');
check('parse_line JPUPDATE jpShared', !is_wp_error($u) && $u['jpShared'] === '867');
check('parse_line JPUPDATE casId', !is_wp_error($u) && $u['casId'] === 'IFCO');

check('parse_line rejects unknown', is_wp_error(Jackpot_Sync_Message_Parser::parse_line('FOO;1;2;3')));
check('parse_line rejects empty', is_wp_error(Jackpot_Sync_Message_Parser::parse_line('')));
check('parse_line rejects short JPUPDATE', is_wp_error(Jackpot_Sync_Message_Parser::parse_line('JPUPDATE;O136')));

echo "\n== Payload validation ==\n";
check('validate rejects non-array', is_wp_error(Jackpot_Sync_Message_Parser::validate_payload('nope')));
check('validate rejects missing jpId', is_wp_error(Jackpot_Sync_Message_Parser::validate_payload(['type' => 'JPCONFIG', 'casId' => 'X'])));
check('validate rejects JPCONFIG without jpName', is_wp_error(Jackpot_Sync_Message_Parser::validate_payload(['type' => 'JPCONFIG', 'jpId' => 'A', 'casId' => 'X'])));
check('validate rejects non-numeric JPUPDATE', is_wp_error(Jackpot_Sync_Message_Parser::validate_payload(['type' => 'JPUPDATE', 'jpId' => 'A', 'casId' => 'X', 'jpValue' => 'abc', 'jpShared' => '1'])));

echo "\n== Prize formatter ==\n";
_jp_reset_store();
$fmt = new Jackpot_Sync_Prize_Formatter();
update_option('jackpot_sync_settings', ['divisor' => 1]);
check('formatter whole units', $fmt->format('53467') === 53467.0);
update_option('jackpot_sync_settings', ['divisor' => 100]);
check('formatter cents', $fmt->format('53467') === 534.67);
check('formatter non-numeric -> 0', $fmt->format('abc') === 0.0);

echo "\n== JPCONFIG create ==\n";
_jp_reset_store();
$proc   = make_processor();
$result = $proc->process(['type' => 'JPCONFIG', 'jpId' => 'O136', 'casId' => 'IFCO', 'jpName' => 'Huff n Puff Mystery LVL2', 'level' => '2', 'jpType' => '11', 'prizeName' => ''], 'test');
check('JPCONFIG returns 201', $result->status === 201);
check('JPCONFIG created flag', $result->body['created'] === true);
$repo = new Jackpot_Sync_Repository();
$pid  = $repo->find_by_jpid('O136');
check('JPCONFIG post exists', $pid > 0);
check('JPCONFIG stored hidden jpId meta', get_post_meta($pid, '_jackpot_jpId', true) === 'O136');
check('JPCONFIG stored legacy jp_id meta', get_post_meta($pid, 'jp_id', true) === 'O136');
check('JPCONFIG stored casId meta', get_post_meta($pid, '_jackpot_casId', true) === 'IFCO');
check('JPCONFIG stored prizeName meta', get_post_meta($pid, '_jackpot_prizeName', true) === '');
check('JPCONFIG post is publish', $GLOBALS['_jp_posts'][$pid]['post_status'] === 'publish');
check('JPCONFIG post title = jpName', $GLOBALS['_jp_posts'][$pid]['post_title'] === 'Huff n Puff Mystery LVL2');
$stats = Jackpot_Sync_Logger::get_stats();
check('JPCONFIG metric created=1', $stats['jackpots_created'] === 1);
check('JPCONFIG metric image missing=1', $stats['images_missing'] === 1);

echo "\n== JPCONFIG duplicate skip ==\n";
$result2 = $proc->process(['type' => 'JPCONFIG', 'jpId' => 'O136', 'casId' => 'IFCO', 'jpName' => 'Huff n Puff Mystery LVL2'], 'test');
check('JPCONFIG duplicate returns 200', $result2->status === 200);
check('JPCONFIG duplicate created=false', $result2->body['created'] === false);
check('JPCONFIG duplicate id matches', $result2->body['id'] === $pid);
$stats = Jackpot_Sync_Logger::get_stats();
check('JPCONFIG metric skipped=1', $stats['jackpots_skipped'] === 1);
check('JPCONFIG no duplicate created', count($GLOBALS['_jp_posts']) === 1);

echo "\n== Image resolver (by jpName) ==\n";
_jp_reset_store();
_jp_seed_attachment('Huff n Puff Mystery LVL2', '2026/01/huff.webp');
update_option('jackpot_sync_settings', ['image_matching_mode' => 'jp_name']);
$proc   = make_processor();
$result = $proc->process(['type' => 'JPCONFIG', 'jpId' => 'O200', 'casId' => 'IFCO', 'jpName' => 'Huff n Puff Mystery LVL2'], 'test');
$repo   = new Jackpot_Sync_Repository();
$pid    = $repo->find_by_jpid('O200');
check('image by jpName assigned', get_post_thumbnail_id($pid) > 0);
check('image by jpName imageFound flag', $result->body['imageFound'] === true);

echo "\n== Image resolver (by jpId filename) ==\n";
_jp_reset_store();
_jp_seed_attachment('some-random-title', '2026/01/O300.png');
update_option('jackpot_sync_settings', ['image_matching_mode' => 'jp_id']);
$proc = make_processor();
$proc->process(['type' => 'JPCONFIG', 'jpId' => 'O300', 'casId' => 'IFCO', 'jpName' => 'Whatever'], 'test');
$repo = new Jackpot_Sync_Repository();
$pid  = $repo->find_by_jpid('O300');
check('image by jpId assigned', get_post_thumbnail_id($pid) > 0);

echo "\n== JPUPDATE ==\n";
_jp_reset_store();
update_option('jackpot_sync_settings', ['divisor' => 100, 'field_amount' => 'amount', 'field_shared' => 'shared_profit_amount']);
$proc = make_processor();
$proc->process(['type' => 'JPCONFIG', 'jpId' => 'O136', 'casId' => 'IFCO', 'jpName' => 'Huff'], 'test');
$repo = new Jackpot_Sync_Repository();
$pid  = $repo->find_by_jpid('O136');
$res  = $proc->process(['type' => 'JPUPDATE', 'jpId' => 'O136', 'casId' => 'IFCO', 'jpValue' => '53467', 'jpShared' => '867'], 'test');
check('JPUPDATE returns 200', $res->status === 200);
check('JPUPDATE amount meta (cents)', (float) get_post_meta($pid, 'amount', true) === 534.67);
check('JPUPDATE shared meta (cents)', (float) get_post_meta($pid, 'shared_profit_amount', true) === 8.67);
check('JPUPDATE body amount', $res->body['amount'] === 534.67);
$stats = Jackpot_Sync_Logger::get_stats();
check('JPUPDATE metric updated=1', $stats['jackpots_updated'] === 1);

echo "\n== JPUPDATE before JPCONFIG (skip) ==\n";
_jp_reset_store();
$proc = make_processor();
$res  = $proc->process(['type' => 'JPUPDATE', 'jpId' => 'NOPE', 'casId' => 'IFCO', 'jpValue' => '1', 'jpShared' => '2'], 'test');
check('JPUPDATE missing returns 202', $res->status === 202);
$stats = Jackpot_Sync_Logger::get_stats();
check('JPUPDATE missing metric skipped=1', $stats['jackpots_skipped'] === 1);

echo "\n== Retention ==\n";
_jp_reset_store();
update_option('jackpot_sync_settings', ['max_keep' => 3, 'cpt' => 'jackpot']);
for ($i = 1; $i <= 6; $i++) {
    $id = wp_insert_post(['post_type' => 'jackpot', 'post_status' => 'publish', 'post_title' => "JP {$i}"]);
    // Ensure deterministic modified ordering.
    $GLOBALS['_jp_posts'][$id]['post_modified'] = sprintf('2026-01-%02d 00:00:00', $i);
}
require $base . 'class-jackpot-retention.php';
$retention = new Jackpot_Sync_Retention(new Jackpot_Sync_Cache());
$deleted   = $retention->run();
check('retention deleted 3', $deleted === 3);
check('retention kept 3', count(get_posts(['post_type' => 'jackpot', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids'])) === 3);

echo "\n== HMAC signature parity (matches Worker) ==\n";
$secret = 'test-secret';
$body   = json_encode(['type' => 'JPUPDATE', 'jpId' => 'O136']);
$sig    = hash_hmac('sha256', $body, $secret);
check('hmac is 64 hex chars', strlen($sig) === 64 && ctype_xdigit($sig));
check('hash_equals constant-time match', hash_equals($sig, hash_hmac('sha256', $body, $secret)));
check('hash_equals rejects wrong', !hash_equals($sig, hash_hmac('sha256', $body, 'wrong')));

echo "\n== MQTT control settings + cache ==\n";
require $base . 'class-jackpot-mqtt-control.php';
_jp_reset_store();
$defaults = Jackpot_Sync_Settings::defaults();
check('settings include worker_url', array_key_exists('worker_url', $defaults));
$san = Jackpot_Sync_Settings::sanitize([
    'secret'     => 'abc',
    'worker_url' => 'https://jackpot-worker.example.workers.dev/',
    'cpt'        => 'jackpot',
]);
check('sanitize keeps worker_url host', strpos($san['worker_url'], 'jackpot-worker.example.workers.dev') !== false);

Jackpot_Sync_Mqtt_Control::cache_status([
    'status'           => 'Running',
    'running'          => true,
    'connectionState'  => 'connected',
    'lastSyncTime'     => '2026-07-14T06:42:00.000Z',
    'lastMessageAt'    => '2026-07-14T06:55:00.000Z',
    'lastConfigUpdate' => '2026-07-14T06:10:00.000Z',
]);
$cached = Jackpot_Sync_Mqtt_Control::get_cached_status();
check('mqtt cache status Running', $cached['status'] === 'Running');
check('mqtt cache running true', $cached['running'] === true);
check('mqtt format_time empty', Jackpot_Sync_Mqtt_Control::format_time('') === '—');
check('mqtt format_time iso', Jackpot_Sync_Mqtt_Control::format_time('2026-07-14T06:42:00.000Z') !== '—');

$fail = Jackpot_Sync_Mqtt_Control::status();
check('mqtt status fails without worker_url', $fail['ok'] === false);
check('mqtt status error code worker_not_configured', ($fail['error_code'] ?? '') === 'worker_not_configured');

echo "\n----------------------------------------\n";
echo "Passed: {$passed}, Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
