<?php
/**
 * Runs when the plugin is deleted from wp-admin. Cleans up stored options.
 * (Jackpot posts are intentionally left in place.)
 *
 * @package JackpotSync
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('jackpot_sync_settings');
delete_option('jackpot_sync_log');
delete_option('jackpot_sync_stats');
delete_option('jackpot_sync_mqtt_status');
wp_clear_scheduled_hook('jackpot_cleanup_event');
