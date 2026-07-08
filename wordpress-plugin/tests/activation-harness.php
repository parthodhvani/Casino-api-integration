<?php
/**
 * Activation + admin-render harness.
 *
 * Loads the plugin main file the way WordPress would, fires the boot +
 * activation hooks, and renders the admin pages under output buffering to
 * surface any runtime PHP errors — all without a real WordPress install.
 *
 * Usage: php wordpress-plugin/tests/activation-harness.php /path/to/jackpot-sync
 *
 * @package JackpotSync\Tests
 */

require __DIR__ . '/wp-stubs.php';

$plugin_dir = $argv[1] ?? (__DIR__ . '/../jackpot-sync');
$plugin_dir = rtrim($plugin_dir, '/');
$main_file  = $plugin_dir . '/jackpot-sync.php';

/* ---- Extra WP stubs needed for load + admin render -------------------- */

$GLOBALS['_jp_hooks'] = ['activation' => null, 'deactivation' => null];

function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_basename($file) { return basename(dirname($file)) . '/' . basename($file); }
function is_admin() { return true; }
function register_activation_hook($file, $cb) { $GLOBALS['_jp_hooks']['activation'] = $cb; }
function register_deactivation_hook($file, $cb) { $GLOBALS['_jp_hooks']['deactivation'] = $cb; }
function add_options_page() { return true; }
function add_management_page() { return true; }
function register_setting() { return true; }
function current_user_can() { return true; }
function get_current_screen() { return null; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . ltrim($p, '/'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url_raw($s) { return (string) $s; }
function checked($a, $b = true, $echo = true) { $r = ((string) $a === (string) $b) ? ' checked' : ''; if ($echo) { echo $r; } return $r; }
function settings_fields() { echo ''; }
function submit_button($text = 'Save') { echo '<button>' . esc_html($text) . '</button>'; }
function wp_nonce_field() { echo '<input type="hidden" name="_wpnonce" value="x">'; }
function wp_nonce_url($url, $a = -1) { return $url . '&_wpnonce=x'; }
function wp_create_nonce() { return 'nonce'; }
function get_current_user_id() { return 1; }

/* ---- Load the plugin like WordPress would ----------------------------- */

require $main_file;

$errors = [];

// Fire plugins_loaded (boots the container + registers hooks).
if (!empty($GLOBALS['_jp_actions']['plugins_loaded'])) {
    foreach ($GLOBALS['_jp_actions']['plugins_loaded'] as $cb) {
        $cb();
    }
}

// Simulate activation.
if (is_callable($GLOBALS['_jp_hooks']['activation'])) {
    $GLOBALS['_jp_hooks']['activation']();
}
$opt = get_option('jackpot_sync_settings');
if (!is_array($opt) || ($opt['field_amount'] ?? '') !== 'amount') {
    $errors[] = 'activation did not seed default settings correctly';
}

// Render both admin pages (catch any output/runtime errors).
$admin = new Jackpot_Sync_Admin(Jackpot_Sync_Plugin::instance()->processor());
ob_start();
$admin->render_settings_page();
$settings_html = ob_get_clean();
if (strpos($settings_html, 'Jackpot Sync') === false) {
    $errors[] = 'settings page did not render expected content';
}

ob_start();
$admin->render_tester_page();
$tester_html = ob_get_clean();
if (strpos($tester_html, 'Jackpot Tester') === false) {
    $errors[] = 'tester page did not render expected content';
}

// Fire the REST route registration (register_routes) to ensure no fatals.
$controller = new Jackpot_Sync_Rest_Controller(Jackpot_Sync_Plugin::instance()->processor());
$controller->register_routes();
$ping = $controller->handle_ping();
if (!($ping instanceof WP_REST_Response) || $ping->get_status() !== 200) {
    $errors[] = 'ping endpoint did not return 200';
}

// Simulate deactivation.
if (is_callable($GLOBALS['_jp_hooks']['deactivation'])) {
    $GLOBALS['_jp_hooks']['deactivation']();
}

if (empty($errors)) {
    echo "ACTIVATION_HARNESS_OK version=" . JACKPOT_SYNC_VERSION . "\n";
    echo "settings_page_bytes=" . strlen($settings_html) . " tester_page_bytes=" . strlen($tester_html) . "\n";
    exit(0);
}

echo "ACTIVATION_HARNESS_FAILED\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
