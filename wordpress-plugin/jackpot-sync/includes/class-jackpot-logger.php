<?php
/**
 * Logger + runtime metrics service.
 *
 * Keeps a rolling in-dashboard activity log and a set of runtime counters that
 * power the admin dashboard cards (created/updated/skipped/images/errors...).
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Structured logging and metrics storage.
 */
class Jackpot_Sync_Logger {

    const LOG_OPTION   = 'jackpot_sync_log';
    const STATS_OPTION = 'jackpot_sync_stats';
    const LOG_LIMIT    = 100;

    /**
     * Append a structured entry to the activity log (newest first).
     *
     * @param string              $message Human readable summary.
     * @param array<string,mixed> $context Structured context.
     * @return void
     */
    public static function log($message, array $context = []) {
        $log = get_option(self::LOG_OPTION, []);
        if (!is_array($log)) {
            $log = [];
        }

        array_unshift($log, [
            'time'    => current_time('mysql'),
            'msg'     => (string) $message,
            'context' => $context,
        ]);

        $log = array_slice($log, 0, self::LOG_LIMIT);
        update_option(self::LOG_OPTION, $log, false);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $suffix = empty($context) ? '' : ' | ' . wp_json_encode($context);
            error_log('[jackpot-sync] ' . $message . $suffix);
        }
    }

    /**
     * Return the activity log.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_log() {
        $log = get_option(self::LOG_OPTION, []);
        return is_array($log) ? $log : [];
    }

    /**
     * Clear the activity log.
     *
     * @return void
     */
    public static function clear_log() {
        update_option(self::LOG_OPTION, [], false);
    }

    /**
     * Default metrics shape.
     *
     * @return array<string,mixed>
     */
    public static function stats_defaults() {
        return [
            'last_update'        => '',
            'last_jpid'          => '',
            'last_casino_id'     => '',
            'last_message_type'  => '',
            'last_config_time'   => '',
            'last_update_time'   => '',
            'last_listener_time' => '',
            'jackpots_created'   => 0,
            'jackpots_updated'   => 0,
            'jackpots_skipped'   => 0,
            'images_found'       => 0,
            'images_missing'     => 0,
            'errors'             => 0,
            'last_error'         => '',
            'last_http_code'     => 0,
            'last_execution_ms'  => 0,
            'listener_status'    => 'No request received yet',
            // MQTT listener control.
            'mqtt_state'         => 'Unknown',
            'mqtt_last_sync'     => '',
            'mqtt_last_message'  => '',
            'mqtt_last_config'   => '',
        ];
    }

    /**
     * Return current metrics merged with defaults.
     *
     * @return array<string,mixed>
     */
    public static function get_stats() {
        $stored = get_option(self::STATS_OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $stats = wp_parse_args($stored, self::stats_defaults());

        // Migrate legacy worker_* keys if present.
        if (empty($stats['listener_status']) && !empty($stored['worker_status'])) {
            $stats['listener_status'] = $stored['worker_status'];
        }
        if (empty($stats['last_listener_time']) && !empty($stored['last_worker_time'])) {
            $stats['last_listener_time'] = $stored['last_worker_time'];
        }

        return $stats;
    }

    /**
     * Merge changes into the metrics store.
     *
     * @param array<string,mixed> $changes Values to update.
     * @return void
     */
    public static function update_stats(array $changes) {
        $stats = self::get_stats();
        update_option(self::STATS_OPTION, array_merge($stats, $changes), false);
    }

    /**
     * Increment a numeric metric.
     *
     * @param string $key    Metric key.
     * @param int    $amount Increment amount.
     * @return void
     */
    public static function increment($key, $amount = 1) {
        $stats         = self::get_stats();
        $current       = isset($stats[$key]) ? (int) $stats[$key] : 0;
        $stats[$key]   = $current + (int) $amount;
        update_option(self::STATS_OPTION, $stats, false);
    }

    /**
     * Reset all metrics to defaults.
     *
     * @return void
     */
    public static function reset_stats() {
        update_option(self::STATS_OPTION, self::stats_defaults(), false);
    }
}
