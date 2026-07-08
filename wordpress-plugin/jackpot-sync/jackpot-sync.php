<?php
/**
 * Plugin Name: Jackpot Sync
 * Description: Receives live jackpot data from the Cloudflare Worker and updates the Jackpot posts + ACF fields. Configure everything under Settings > Jackpot Sync.
 * Version:     2.1.0
 * Author:      Rise Web
 * License:     GPLv2 or later
 *
 * No file editing required — after activating, go to Settings > Jackpot Sync
 * and paste the shared secret. The shared secret may optionally be locked in
 * wp-config.php with: define('JACKPOT_SECRET', '...');
 */

if (!defined('ABSPATH')) {
    exit;
}

define('JACKPOT_SYNC_VERSION', '2.1.0');
define('JACKPOT_SYNC_DIR', plugin_dir_path(__FILE__));

require_once JACKPOT_SYNC_DIR . 'includes/helpers.php';
require_once JACKPOT_SYNC_DIR . 'includes/services.php';
require_once JACKPOT_SYNC_DIR . 'includes/rest-api.php';
require_once JACKPOT_SYNC_DIR . 'includes/retention.php';

if (is_admin()) {
    require_once JACKPOT_SYNC_DIR . 'includes/admin-settings.php';
}

/* -- Activation / deactivation ------------------------------------------ */

register_activation_hook(__FILE__, function () {
    // Seed default settings so the page shows sensible values immediately.
    if (get_option('jackpot_sync_settings') === false) {
        add_option('jackpot_sync_settings', jackpot_default_settings());
    }

    if (!wp_next_scheduled('jackpot_cleanup_event')) {
        wp_schedule_event(time(), 'hourly', 'jackpot_cleanup_event');
    }

    // Trigger the "open settings page after activation" redirect.
    set_transient('jackpot_sync_activated', 1, 30);
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('jackpot_cleanup_event');
});

/* -- Handy "Settings" link on the Plugins list --------------------------- */

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('options-general.php?page=jackpot-sync');
    array_unshift($links, '<a href="' . esc_url($url) . '">Settings</a>');
    return $links;
});
