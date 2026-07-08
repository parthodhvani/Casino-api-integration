<?php
/**
 * Runs when the plugin is deleted from wp-admin. Cleans up stored options.
 * (Jackpot posts are intentionally left in place.)
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('jackpot_sync_settings');
delete_option('jackpot_sync_log');
wp_clear_scheduled_hook('jackpot_cleanup_event');
