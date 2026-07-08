<?php
/**
 * Plugin Name: Jackpot Sync
 * Description: Fully automated real-time jackpot sync. Receives signed jackpot data from the Cloudflare Worker and creates/updates Jackpot posts + ACF fields automatically. Configure under Settings > Jackpot Sync.
 * Version:     3.0.0
 * Author:      Rise Web
 * License:     GPLv2 or later
 * Text Domain: jackpot-sync
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * No file editing required — after activating, go to Settings > Jackpot Sync
 * and paste the shared secret. The shared secret may optionally be locked in
 * wp-config.php with: define('JACKPOT_SECRET', '...');
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

define('JACKPOT_SYNC_VERSION', '3.0.0');
define('JACKPOT_SYNC_DIR', plugin_dir_path(__FILE__));
define('JACKPOT_SYNC_BASENAME', plugin_basename(__FILE__));

/*
 * Load service classes. Order matters only for the container, which is loaded
 * last; individual classes have no load-time dependencies on each other.
 */
require_once JACKPOT_SYNC_DIR . 'includes/class-jackpot-settings.php';
require_once JACKPOT_SYNC_DIR . 'includes/class-jackpot-logger.php';
require_once JACKPOT_SYNC_DIR . 'includes/class-jackpot-message-parser.php';
require_once JACKPOT_SYNC_DIR . 'includes/class-jackpot-prize-formatter.php';
require_once JACKPOT_SYNC_DIR . 'includes/class-jackpot-image-resolver.php';
require_once JACKPOT_SYNC_DIR . 'includes/class-jackpot-repository.php';
require_once JACKPOT_SYNC_DIR . 'includes/class-jackpot-cache.php';
require_once JACKPOT_SYNC_DIR . 'includes/class-jackpot-result.php';
require_once JACKPOT_SYNC_DIR . 'includes/class-jackpot-creator.php';
require_once JACKPOT_SYNC_DIR . 'includes/class-jackpot-updater.php';
require_once JACKPOT_SYNC_DIR . 'includes/class-jackpot-processor.php';
require_once JACKPOT_SYNC_DIR . 'includes/class-jackpot-rest-controller.php';
require_once JACKPOT_SYNC_DIR . 'includes/class-jackpot-retention.php';
require_once JACKPOT_SYNC_DIR . 'includes/class-jackpot-plugin.php';

if (is_admin()) {
    require_once JACKPOT_SYNC_DIR . 'includes/class-jackpot-admin.php';
}

// Backward-compatibility procedural API (delegates to the classes above).
require_once JACKPOT_SYNC_DIR . 'includes/helpers.php';

/*
 * Boot the plugin once WordPress is ready.
 */
add_action('plugins_loaded', function () {
    Jackpot_Sync_Plugin::instance()->register();
});

/* -- Activation / deactivation ------------------------------------------ */

register_activation_hook(__FILE__, function () {
    if (get_option(Jackpot_Sync_Settings::OPTION) === false) {
        add_option(Jackpot_Sync_Settings::OPTION, Jackpot_Sync_Settings::defaults());
    }

    Jackpot_Sync_Retention::schedule();

    // Open the settings page right after activation.
    set_transient('jackpot_sync_activated', 1, 30);
});

register_deactivation_hook(__FILE__, function () {
    Jackpot_Sync_Retention::unschedule();
});
