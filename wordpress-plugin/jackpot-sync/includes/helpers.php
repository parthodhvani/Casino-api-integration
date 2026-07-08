<?php
/**
 * Shared helpers: settings access + activity logging.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Default settings. These are what a fresh install starts with.
 */
function jackpot_default_settings() {
    return [
        'secret'              => '',
        'cpt'                 => 'jackpot',
        'field_amount'        => 'amount',
        'field_shared'        => 'shared_profit_amount',
        'divisor'             => 1, // 1 = values are whole units, 100 = values are cents.
        'max_keep'            => 20,
        'image_matching_mode' => 'jp_name',
    ];
}

/**
 * Read a single setting.
 *
 * The shared secret can also be defined in wp-config.php as JACKPOT_SECRET,
 * which always takes priority over the value saved on the settings page.
 */
function jackpot_get($key) {
    if ($key === 'secret' && defined('JACKPOT_SECRET') && JACKPOT_SECRET !== '') {
        return JACKPOT_SECRET;
    }

    $settings = wp_parse_args(
        get_option('jackpot_sync_settings', []),
        jackpot_default_settings()
    );

    return isset($settings[$key]) ? $settings[$key] : null;
}

/**
 * The public REST endpoint URL for this site.
 */
function jackpot_endpoint_url() {
    return rest_url('jackpot/v1/update');
}

function jackpot_ping_url() {
    return rest_url('jackpot/v1/ping');
}

/**
 * Append a line to the in-dashboard activity log (keeps the newest 50).
 * Also writes to the PHP error log when WP_DEBUG is on.
 */
function jackpot_log($message, array $context = []) {
    $context_string = '';
    if (!empty($context)) {
        $context_string = ' | ' . wp_json_encode($context);
    }

    $log = get_option('jackpot_sync_log', []);
    if (!is_array($log)) {
        $log = [];
    }

    array_unshift($log, [
        'time'    => current_time('mysql'),
        'msg'     => (string) $message,
        'context' => $context,
    ]);

    $log = array_slice($log, 0, 50);
    update_option('jackpot_sync_log', $log, false);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[jackpot-sync] ' . $message . $context_string);
    }
}

/**
 * Return plugin runtime metrics for admin dashboard cards.
 *
 * @return array<string,mixed>
 */
function jackpot_get_stats() {
    $defaults = [
        'last_update'       => '',
        'last_jpid'         => '',
        'jackpots_created'  => 0,
        'jackpots_updated'  => 0,
        'jackpots_skipped'  => 0,
        'images_found'      => 0,
        'images_missing'    => 0,
        'last_error'        => '',
        'worker_status'     => 'No request received yet',
        'last_http_code'    => 0,
        'last_message_type' => '',
        'last_execution_ms' => 0,
        'last_casino_id'    => '',
    ];

    return wp_parse_args(get_option('jackpot_sync_stats', []), $defaults);
}

/**
 * Persist runtime metrics for admin dashboard cards.
 */
function jackpot_update_stats(array $changes) {
    $stats = jackpot_get_stats();
    $next  = array_merge($stats, $changes);
    update_option('jackpot_sync_stats', $next, false);
}

/**
 * Helper: increment a numeric metric.
 */
function jackpot_increment_stat($key, $amount = 1) {
    $stats = jackpot_get_stats();
    $curr  = isset($stats[ $key ]) ? (int) $stats[ $key ] : 0;
    $stats[ $key ] = $curr + (int) $amount;
    update_option('jackpot_sync_stats', $stats, false);
}

/**
 * Convert a parsed semicolon line into the canonical JSON payload used by REST.
 *
 * @return array<string,mixed>|WP_Error
 */
function jackpot_parse_line_to_payload($line) {
    $line = trim((string) $line);
    if ($line === '') {
        return new WP_Error('empty_line', 'Message line is empty.');
    }

    $parts = array_map('trim', explode(';', $line));
    $type  = isset($parts[0]) ? strtoupper($parts[0]) : '';

    if ($type === 'JPCONFIG') {
        if (count($parts) < 7) {
            return new WP_Error('invalid_config', 'JPCONFIG requires at least 7 segments.');
        }

        return [
            'type'      => 'JPCONFIG',
            'jpId'      => sanitize_text_field($parts[1]),
            'level'     => sanitize_text_field($parts[2]),
            'jpType'    => sanitize_text_field($parts[3]),
            'jpName'    => sanitize_text_field($parts[4]),
            'prizeName' => sanitize_text_field($parts[5]),
            'casId'     => sanitize_text_field($parts[6]),
        ];
    }

    if ($type === 'JPUPDATE') {
        if (count($parts) < 9) {
            return new WP_Error('invalid_update', 'JPUPDATE requires at least 9 segments.');
        }

        return [
            'type'     => 'JPUPDATE',
            'jpId'     => sanitize_text_field($parts[1]),
            'level'    => sanitize_text_field($parts[2]),
            'jpValue'  => $parts[5],
            'jpShared' => $parts[6],
            'casId'    => sanitize_text_field($parts[8]),
        ];
    }

    return new WP_Error('unsupported_type', 'Unsupported message type: ' . $type);
}
