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
        'secret'       => '',
        'cpt'          => 'jackpot',
        'field_amount' => 'jackpot_amount',
        'field_shared' => 'shared_profit_amount',
        'divisor'      => 1,   // 1 = values are whole euros, 100 = values are cents
        'max_keep'     => 20,
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
function jackpot_log($message) {
    $log = get_option('jackpot_sync_log', []);
    if (!is_array($log)) {
        $log = [];
    }

    array_unshift($log, [
        'time' => current_time('mysql'),
        'msg'  => (string) $message,
    ]);

    $log = array_slice($log, 0, 50);
    update_option('jackpot_sync_log', $log, false);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[jackpot-sync] ' . $message);
    }
}
