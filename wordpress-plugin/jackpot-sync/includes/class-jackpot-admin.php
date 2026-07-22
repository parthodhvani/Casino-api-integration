<?php
/**
 * Admin UI.
 *
 * Top-level "Jackpot Sync" menu with subpages:
 * Dashboard (MQTT + health), Settings, Activity Log, Tester.
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin pages for Jackpot Sync.
 */
class Jackpot_Sync_Admin {

    const MENU_SLUG     = 'jackpot-sync';
    const PAGE_SETTINGS = 'jackpot-sync-settings';
    const PAGE_LOG      = 'jackpot-sync-log';
    const PAGE_TESTER   = 'jackpot-sync-tester';

    /** @var Jackpot_Sync_Processor */
    private $processor;

    /**
     * @param Jackpot_Sync_Processor $processor Message processor.
     */
    public function __construct(Jackpot_Sync_Processor $processor) {
        $this->processor = $processor;
    }

    /**
     * Register admin hooks.
     *
     * @return void
     */
    public function register() {
        add_action('admin_menu', [$this, 'register_menus']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'maybe_activation_redirect']);
        add_action('admin_notices', [$this, 'maybe_config_notice']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_jackpot_process_test_message', [$this, 'handle_test_message']);
        add_action('admin_post_jackpot_clear_log', [$this, 'handle_clear_log']);
        add_action('wp_ajax_jackpot_mqtt_start', [$this, 'ajax_mqtt_start']);
        add_action('wp_ajax_jackpot_mqtt_stop', [$this, 'ajax_mqtt_stop']);
        add_action('wp_ajax_jackpot_mqtt_status', [$this, 'ajax_mqtt_status']);
        add_filter('plugin_action_links_' . JACKPOT_SYNC_BASENAME, [$this, 'add_settings_link']);
    }

    /**
     * Admin URL for a plugin page.
     *
     * @param string $slug Page slug (default: dashboard).
     * @return string
     */
    public static function page_url($slug = self::MENU_SLUG) {
        return admin_url('admin.php?page=' . rawurlencode($slug));
    }

    /**
     * Whether the current admin hook belongs to this plugin.
     *
     * @param string $hook Current admin page hook.
     * @return bool
     */
    private function is_plugin_hook($hook) {
        $hooks = [
            'toplevel_page_' . self::MENU_SLUG,
            self::MENU_SLUG . '_page_' . self::PAGE_SETTINGS,
            self::MENU_SLUG . '_page_' . self::PAGE_LOG,
            self::MENU_SLUG . '_page_' . self::PAGE_TESTER,
        ];
        return in_array($hook, $hooks, true);
    }

    /**
     * Enqueue admin assets on Jackpot Sync pages.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_assets($hook) {
        if (!$this->is_plugin_hook($hook)) {
            return;
        }

        wp_enqueue_style(
            'jackpot-sync-admin',
            JACKPOT_SYNC_URL . 'assets/admin.css',
            [],
            JACKPOT_SYNC_VERSION
        );

        // MQTT controls live on the Dashboard only.
        if ($hook !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_script(
            'jackpot-sync-admin',
            JACKPOT_SYNC_URL . 'assets/admin.js',
            [],
            JACKPOT_SYNC_VERSION,
            true
        );

        wp_localize_script('jackpot-sync-admin', 'JackpotSyncAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('jackpot_mqtt_control'),
            'i18n'    => [
                'starting'   => __('Starting MQTT…', 'jackpot-sync'),
                'stopping'   => __('Stopping MQTT…', 'jackpot-sync'),
                'refreshing' => __('Refreshing status…', 'jackpot-sync'),
                'running'    => __('Running', 'jackpot-sync'),
                'stopped'    => __('Stopped', 'jackpot-sync'),
                'unknown'    => __('Unknown', 'jackpot-sync'),
                'error'      => __('Something went wrong. Please try again.', 'jackpot-sync'),
            ],
        ]);
    }

    /**
     * Register top-level menu + subpages.
     *
     * @return void
     */
    public function register_menus() {
        add_menu_page(
            __('Jackpot Sync', 'jackpot-sync'),
            __('Jackpot Sync', 'jackpot-sync'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_dashboard_page'],
            'dashicons-chart-area',
            58
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Dashboard', 'jackpot-sync'),
            __('Dashboard', 'jackpot-sync'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_dashboard_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'jackpot-sync'),
            __('Settings', 'jackpot-sync'),
            'manage_options',
            self::PAGE_SETTINGS,
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Activity Log', 'jackpot-sync'),
            __('Activity Log', 'jackpot-sync'),
            'manage_options',
            self::PAGE_LOG,
            [$this, 'render_log_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Tester', 'jackpot-sync'),
            __('Tester', 'jackpot-sync'),
            'manage_options',
            self::PAGE_TESTER,
            [$this, 'render_tester_page']
        );
    }

    /**
     * Register the settings option.
     *
     * @return void
     */
    public function register_settings() {
        register_setting('jackpot_sync_group', Jackpot_Sync_Settings::OPTION, [
            'sanitize_callback' => ['Jackpot_Sync_Settings', 'sanitize'],
            'default'           => Jackpot_Sync_Settings::defaults(),
        ]);
    }

    /**
     * Add a Settings link on the Plugins list.
     *
     * @param array<int,string> $links Existing links.
     * @return array<int,string>
     */
    public function add_settings_link($links) {
        $url = self::page_url(self::PAGE_SETTINGS);
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'jackpot-sync') . '</a>');
        array_unshift($links, '<a href="' . esc_url(self::page_url()) . '">' . esc_html__('Dashboard', 'jackpot-sync') . '</a>');
        return $links;
    }

    /**
     * Redirect to dashboard once, right after activation.
     *
     * @return void
     */
    public function maybe_activation_redirect() {
        if (get_transient('jackpot_sync_activated')) {
            delete_transient('jackpot_sync_activated');
            if (!isset($_GET['activate-multi'])) {
                wp_safe_redirect(self::page_url());
                exit;
            }
        }
    }

    /**
     * Nag when the secret is not configured yet.
     *
     * @return void
     */
    public function maybe_config_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = get_current_screen();
        if ($screen && strpos((string) $screen->id, 'jackpot-sync') !== false) {
            return;
        }

        if (empty(Jackpot_Sync_Settings::get('secret'))) {
            $url = self::page_url(self::PAGE_SETTINGS);
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . '<strong>Jackpot Sync</strong> is active but not configured yet. '
                . '<a href="' . esc_url($url) . '">' . esc_html__('Add your shared secret', 'jackpot-sync') . '</a>'
                . '</p></div>';
        }
    }

    /**
     * Handle the admin tester submission.
     *
     * @return void
     */
    public function handle_test_message() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'jackpot-sync'));
        }

        check_admin_referer('jackpot_sync_test_message');

        $raw    = isset($_POST['jackpot_test_message']) ? wp_unslash($_POST['jackpot_test_message']) : '';
        $parsed = Jackpot_Sync_Message_Parser::parse_line($raw);

        if (is_wp_error($parsed)) {
            $result = ['ok' => false, 'status' => 400, 'message' => $parsed->get_error_message()];
        } else {
            $processed = $this->processor->process($parsed, 'admin_tester');
            $result    = [
                'ok'      => $processed->is_success(),
                'status'  => $processed->status,
                'message' => wp_json_encode($processed->body),
            ];
        }

        set_transient('jackpot_sync_test_result_' . get_current_user_id(), $result, 120);
        wp_safe_redirect(self::page_url(self::PAGE_TESTER));
        exit;
    }

    /**
     * Clear the activity log.
     *
     * @return void
     */
    public function handle_clear_log() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'jackpot-sync'));
        }

        check_admin_referer('jackpot_sync_clear_log');
        Jackpot_Sync_Logger::clear_log();
        wp_safe_redirect(self::page_url(self::PAGE_LOG));
        exit;
    }

    /**
     * AJAX: start MQTT listener.
     *
     * @return void
     */
    public function ajax_mqtt_start() {
        $this->ajax_mqtt_dispatch('start');
    }

    /**
     * AJAX: stop MQTT listener.
     *
     * @return void
     */
    public function ajax_mqtt_stop() {
        $this->ajax_mqtt_dispatch('stop');
    }

    /**
     * AJAX: refresh MQTT status.
     *
     * @return void
     */
    public function ajax_mqtt_status() {
        $this->ajax_mqtt_dispatch('status');
    }

    /**
     * Shared AJAX dispatcher for MQTT control actions.
     *
     * @param string $action start|stop|status
     * @return void
     */
    private function ajax_mqtt_dispatch($action) {
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to manage MQTT.', 'jackpot-sync'),
            ], 403);
        }

        check_ajax_referer('jackpot_mqtt_control', 'nonce');

        if ($action === 'start') {
            $result = Jackpot_Sync_Mqtt_Control::start();
        } elseif ($action === 'stop') {
            $result = Jackpot_Sync_Mqtt_Control::stop();
        } else {
            $result = Jackpot_Sync_Mqtt_Control::status();
        }

        $status = !empty($result['status_payload'])
            ? $result['status_payload']
            : Jackpot_Sync_Mqtt_Control::get_cached_status();

        $payload = [
            'ok'      => !empty($result['ok']),
            'message' => isset($result['message']) ? (string) $result['message'] : '',
            'action'  => $action,
            'status'  => $this->present_mqtt_status($status),
            'raw'     => isset($result['data']) ? $result['data'] : null,
        ];

        if (!empty($result['ok'])) {
            wp_send_json_success($payload);
        }

        wp_send_json_error($payload, !empty($result['http_code']) ? (int) $result['http_code'] : 400);
    }

    /**
     * Shape MQTT status for the admin UI (escaped display strings).
     *
     * @param array<string,mixed> $status Raw/cached status.
     * @return array<string,mixed>
     */
    private function present_mqtt_status(array $status) {
        $running = !empty($status['running']) || (isset($status['status']) && strcasecmp((string) $status['status'], 'Running') === 0);
        $label   = $running ? 'Running' : ((isset($status['status']) && $status['status'] !== 'Unknown') ? (string) $status['status'] : 'Stopped');
        if (isset($status['status']) && strcasecmp((string) $status['status'], 'Unknown') === 0 && !isset($status['running'])) {
            $label   = 'Unknown';
            $running = false;
        }

        return [
            'running'            => $running,
            'label'              => $label,
            'connectionState'    => isset($status['connectionState']) ? (string) $status['connectionState'] : 'unknown',
            'lastSyncTime'       => isset($status['lastSyncTime']) ? (string) $status['lastSyncTime'] : '',
            'lastMessageAt'      => isset($status['lastMessageAt']) ? (string) $status['lastMessageAt'] : '',
            'lastConfigUpdate'   => isset($status['lastConfigUpdate']) ? (string) $status['lastConfigUpdate'] : '',
            'lastSyncDisplay'    => Jackpot_Sync_Mqtt_Control::format_time($status['lastSyncTime'] ?? ''),
            'lastMessageDisplay' => Jackpot_Sync_Mqtt_Control::format_time($status['lastMessageAt'] ?? ''),
            'lastConfigDisplay'  => Jackpot_Sync_Mqtt_Control::format_time($status['lastConfigUpdate'] ?? ''),
            'lastError'          => isset($status['lastError']) ? (string) $status['lastError'] : '',
            'fetched_at'         => isset($status['fetched_at']) ? (string) $status['fetched_at'] : '',
        ];
    }

    /**
     * Build the health-check rows.
     *
     * @return array<int,array<string,mixed>>
     */
    private function status_rows() {
        $secret   = Jackpot_Sync_Settings::get('secret');
        $cpt      = Jackpot_Sync_Settings::get('cpt');
        $constant = Jackpot_Sync_Settings::secret_is_constant();

        return [
            [
                'label' => 'Shared secret',
                'ok'    => !empty($secret),
                'good'  => $constant ? 'Set via wp-config.php (JACKPOT_SECRET)' : 'Set in Settings',
                'bad'   => 'Not set — paste the same secret used on the Node listener (JACKPOT_SECRET)',
            ],
            [
                'label' => 'Listener URL',
                'ok'    => !empty(Jackpot_Sync_Settings::get('listener_url')),
                'good'  => 'Set — MQTT Start/Stop/Status can reach the Node listener',
                'bad'   => 'Not set — add the Node listener control URL in Settings (e.g. https://mqtt.waayup.be)',
            ],
            [
                'label' => 'ACF active',
                'ok'    => function_exists('update_field'),
                'good'  => 'Advanced Custom Fields is active',
                'bad'   => 'update_field() not found — is ACF active?',
            ],
            [
                'label' => 'Custom Post Type',
                'ok'    => post_type_exists($cpt),
                'good'  => 'CPT "' . esc_html($cpt) . '" exists',
                'bad'   => 'CPT "' . esc_html($cpt) . '" not found — check the slug in Settings',
            ],
            [
                'label' => 'Retention cron',
                'ok'    => (bool) wp_next_scheduled(Jackpot_Sync_Retention::HOOK),
                'good'  => 'Hourly cleanup scheduled',
                'bad'   => 'Cron not scheduled — deactivate/reactivate the plugin',
            ],
        ];
    }

    /**
     * Shared page chrome: title + horizontal tabs.
     *
     * @param string $current Current page slug.
     * @param string $subtitle Optional subtitle under the title.
     * @return void
     */
    private function render_header($current, $subtitle = '') {
        $tabs = [
            self::MENU_SLUG     => __('Dashboard', 'jackpot-sync'),
            self::PAGE_SETTINGS => __('Settings', 'jackpot-sync'),
            self::PAGE_LOG      => __('Activity Log', 'jackpot-sync'),
            self::PAGE_TESTER   => __('Tester', 'jackpot-sync'),
        ];
        ?>
        <h1 class="wp-heading-inline">
            <?php esc_html_e('Jackpot Sync', 'jackpot-sync'); ?>
            <span class="jackpot-sync-version">v<?php echo esc_html(JACKPOT_SYNC_VERSION); ?></span>
        </h1>
        <?php if ($subtitle !== '') : ?>
            <p class="jackpot-sync-subtitle"><?php echo esc_html($subtitle); ?></p>
        <?php endif; ?>
        <nav class="nav-tab-wrapper jackpot-sync-tabs" aria-label="<?php esc_attr_e('Jackpot Sync sections', 'jackpot-sync'); ?>">
            <?php foreach ($tabs as $slug => $label) : ?>
                <a href="<?php echo esc_url(self::page_url($slug)); ?>"
                   class="nav-tab <?php echo $current === $slug ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /**
     * Dashboard: MQTT control, runtime, health, endpoint.
     *
     * @return void
     */
    public function render_dashboard_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $endpoint = Jackpot_Sync_Settings::endpoint_url();
        $ping     = Jackpot_Sync_Settings::ping_url();
        $stats    = Jackpot_Sync_Logger::get_stats();
        $mqtt     = $this->present_mqtt_status(Jackpot_Sync_Mqtt_Control::get_cached_status());
        ?>
        <div class="wrap jackpot-sync-wrap">
            <?php
            $this->render_header(
                self::MENU_SLUG,
                __('Live MQTT control, health checks, and runtime metrics.', 'jackpot-sync')
            );
            ?>

            <div id="jackpot-mqtt-notice" class="jackpot-mqtt-notice" hidden></div>

            <h2 class="title"><?php esc_html_e('MQTT Listener', 'jackpot-sync'); ?></h2>
            <div class="jackpot-mqtt-panel" id="jackpot-mqtt-panel"
                 data-running="<?php echo $mqtt['running'] ? '1' : '0'; ?>">
                <table class="widefat striped" style="max-width:820px">
                    <tbody>
                    <tr>
                        <td style="width:230px"><strong><?php esc_html_e('MQTT Status', 'jackpot-sync'); ?></strong></td>
                        <td>
                            <span id="jackpot-mqtt-status-indicator" class="jackpot-mqtt-indicator <?php echo $mqtt['running'] ? 'is-running' : 'is-stopped'; ?>">
                                <span class="jackpot-mqtt-dot" aria-hidden="true"></span>
                                <strong id="jackpot-mqtt-status-label"><?php echo esc_html($mqtt['label']); ?></strong>
                            </span>
                            <span class="description" style="margin-left:8px">
                                <?php esc_html_e('Connection:', 'jackpot-sync'); ?>
                                <code id="jackpot-mqtt-connection"><?php echo esc_html($mqtt['connectionState']); ?></code>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Last Sync', 'jackpot-sync'); ?></strong></td>
                        <td id="jackpot-mqtt-last-sync"><?php echo esc_html($mqtt['lastSyncDisplay']); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Last Message', 'jackpot-sync'); ?></strong></td>
                        <td id="jackpot-mqtt-last-message"><?php echo esc_html($mqtt['lastMessageDisplay']); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Last Configuration Update', 'jackpot-sync'); ?></strong></td>
                        <td id="jackpot-mqtt-last-config"><?php echo esc_html($mqtt['lastConfigDisplay']); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Listener Last Error', 'jackpot-sync'); ?></strong></td>
                        <td id="jackpot-mqtt-last-error"><?php echo esc_html($mqtt['lastError'] !== '' ? $mqtt['lastError'] : '—'); ?></td>
                    </tr>
                    </tbody>
                </table>

                <p class="jackpot-mqtt-actions" style="margin-top:12px">
                    <button type="button" class="button button-primary" id="jackpot-mqtt-start">
                        <?php esc_html_e('Start MQTT', 'jackpot-sync'); ?>
                    </button>
                    <button type="button" class="button" id="jackpot-mqtt-stop">
                        <?php esc_html_e('Stop MQTT', 'jackpot-sync'); ?>
                    </button>
                    <button type="button" class="button" id="jackpot-mqtt-refresh">
                        <?php esc_html_e('Refresh Status', 'jackpot-sync'); ?>
                    </button>
                    <span id="jackpot-mqtt-busy" class="spinner" style="float:none;margin-top:4px"></span>
                </p>
                <p class="description">
                    <?php esc_html_e('Controls the Node MQTT listener directly. The Node process stays running — only the MQTT connection starts or stops.', 'jackpot-sync'); ?>
                    <?php
                    echo ' ';
                    printf(
                        /* translators: %s: settings page URL */
                        esc_html__('Configure the listener URL under %s.', 'jackpot-sync'),
                        '<a href="' . esc_url(self::page_url(self::PAGE_SETTINGS)) . '">' . esc_html__('Settings', 'jackpot-sync') . '</a>'
                    );
                    ?>
                </p>
            </div>

            <h2 class="title"><?php esc_html_e('Runtime overview', 'jackpot-sync'); ?></h2>
            <table class="widefat striped" style="max-width:820px">
                <tbody>
                <tr><td style="width:230px"><strong><?php esc_html_e('Last MQTT Update', 'jackpot-sync'); ?></strong></td><td><?php echo esc_html($stats['last_update'] ?: '—'); ?></td></tr>
                <tr><td><strong><?php esc_html_e('Last JPID', 'jackpot-sync'); ?></strong></td><td><?php echo esc_html($stats['last_jpid'] ?: '—'); ?> <?php echo $stats['last_message_type'] ? '(' . esc_html($stats['last_message_type']) . ')' : ''; ?></td></tr>
                <tr><td><strong><?php esc_html_e('Last Casino ID', 'jackpot-sync'); ?></strong></td><td><?php echo esc_html($stats['last_casino_id'] ?: '—'); ?></td></tr>
                <tr><td><strong><?php esc_html_e('Last JPCONFIG', 'jackpot-sync'); ?></strong></td><td><?php echo esc_html($stats['last_config_time'] ?: '—'); ?></td></tr>
                <tr><td><strong><?php esc_html_e('Last JPUPDATE', 'jackpot-sync'); ?></strong></td><td><?php echo esc_html($stats['last_update_time'] ?: '—'); ?></td></tr>
                <tr><td><strong><?php esc_html_e('Jackpots Created', 'jackpot-sync'); ?></strong></td><td><?php echo esc_html((string) $stats['jackpots_created']); ?></td></tr>
                <tr><td><strong><?php esc_html_e('Jackpots Updated', 'jackpot-sync'); ?></strong></td><td><?php echo esc_html((string) $stats['jackpots_updated']); ?></td></tr>
                <tr><td><strong><?php esc_html_e('Jackpots Skipped', 'jackpot-sync'); ?></strong></td><td><?php echo esc_html((string) $stats['jackpots_skipped']); ?></td></tr>
                <tr><td><strong><?php esc_html_e('Images Found', 'jackpot-sync'); ?></strong></td><td><?php echo esc_html((string) $stats['images_found']); ?></td></tr>
                <tr><td><strong><?php esc_html_e('Images Missing', 'jackpot-sync'); ?></strong></td><td><?php echo esc_html((string) $stats['images_missing']); ?></td></tr>
                <tr><td><strong><?php esc_html_e('Errors', 'jackpot-sync'); ?></strong></td><td><?php echo esc_html((string) $stats['errors']); ?></td></tr>
                <tr><td><strong><?php esc_html_e('Last Error', 'jackpot-sync'); ?></strong></td><td><?php echo esc_html($stats['last_error'] ?: '—'); ?></td></tr>
                <tr><td><strong><?php esc_html_e('Last Execution Time', 'jackpot-sync'); ?></strong></td><td><?php echo esc_html((string) $stats['last_execution_ms']); ?> ms</td></tr>
                <tr><td><strong><?php esc_html_e('Last Incoming Request', 'jackpot-sync'); ?></strong></td><td><?php echo esc_html($stats['listener_status']); ?> <?php echo $stats['last_http_code'] ? '(HTTP ' . esc_html((string) $stats['last_http_code']) . ')' : ''; ?></td></tr>
                <tr><td><strong><?php esc_html_e('MQTT State (cached)', 'jackpot-sync'); ?></strong></td><td><?php echo esc_html($stats['mqtt_state'] ?: 'Unknown'); ?></td></tr>
                <tr><td><strong><?php esc_html_e('Plugin Version', 'jackpot-sync'); ?></strong></td><td><?php echo esc_html(JACKPOT_SYNC_VERSION); ?></td></tr>
                </tbody>
            </table>

            <h2 class="title"><?php esc_html_e('Health', 'jackpot-sync'); ?></h2>
            <table class="widefat striped" style="max-width:820px">
                <tbody>
                <?php foreach ($this->status_rows() as $row) : ?>
                    <tr>
                        <td style="width:170px"><strong><?php echo esc_html($row['label']); ?></strong></td>
                        <td>
                            <?php if ($row['ok']) : ?>
                                <span style="color:#1a7f37">&#10003; <?php echo esc_html($row['good']); ?></span>
                            <?php else : ?>
                                <span style="color:#b32d2e">&#10007; <?php echo esc_html($row['bad']); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 class="title"><?php esc_html_e('Endpoint', 'jackpot-sync'); ?></h2>
            <p><?php esc_html_e('Paste this URL into the Node listener WP_SITES environment variable:', 'jackpot-sync'); ?></p>
            <input type="text" readonly onclick="this.select()" style="width:100%;max-width:820px;font-family:monospace"
                   value="<?php echo esc_attr($endpoint); ?>">
            <p class="description"><?php esc_html_e('Health check:', 'jackpot-sync'); ?>
                <a href="<?php echo esc_url($ping); ?>" target="_blank" rel="noreferrer"><?php echo esc_html($ping); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Settings form page.
     *
     * @return void
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $s        = Jackpot_Sync_Settings::all();
        $constant = Jackpot_Sync_Settings::secret_is_constant();
        $listener = Jackpot_Sync_Settings::get('listener_url');
        ?>
        <div class="wrap jackpot-sync-wrap">
            <?php
            $this->render_header(
                self::PAGE_SETTINGS,
                __('Shared secret, MQTT listener URL, CPT, ACF fields, and retention.', 'jackpot-sync')
            );
            ?>

            <form method="post" action="options.php">
                <?php settings_fields('jackpot_sync_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="jp_secret"><?php esc_html_e('Shared secret', 'jackpot-sync'); ?></label></th>
                        <td>
                            <?php if ($constant) : ?>
                                <p><em><?php esc_html_e('Locked — defined in wp-config.php as JACKPOT_SECRET.', 'jackpot-sync'); ?></em></p>
                            <?php else : ?>
                                <input type="text" id="jp_secret" name="jackpot_sync_settings[secret]"
                                       value="<?php echo esc_attr($s['secret']); ?>"
                                       style="width:100%;max-width:820px;font-family:monospace">
                                <p class="description"><?php esc_html_e('Must exactly match JACKPOT_SECRET on the Node MQTT listener. Used to verify incoming jackpot POSTs and to sign MQTT control requests.', 'jackpot-sync'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="jp_listener_url"><?php esc_html_e('MQTT Listener URL', 'jackpot-sync'); ?></label></th>
                        <td>
                            <input type="url" id="jp_listener_url" name="jackpot_sync_settings[listener_url]"
                                   value="<?php echo esc_attr($listener); ?>"
                                   placeholder="https://mqtt.waayup.be"
                                   style="width:100%;max-width:820px;font-family:monospace">
                            <p class="description"><?php esc_html_e('Base URL of the Node control API used by Start / Stop / Refresh Status (no trailing path). Example: https://mqtt.waayup.be', 'jackpot-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="jp_cpt"><?php esc_html_e('Jackpot post type slug', 'jackpot-sync'); ?></label></th>
                        <td><input type="text" id="jp_cpt" name="jackpot_sync_settings[cpt]"
                                   value="<?php echo esc_attr($s['cpt']); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Default: jackpot', 'jackpot-sync'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="jp_field_amount"><?php esc_html_e('ACF field — amount', 'jackpot-sync'); ?></label></th>
                        <td><input type="text" id="jp_field_amount" name="jackpot_sync_settings[field_amount]"
                                   value="<?php echo esc_attr($s['field_amount']); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Default: amount', 'jackpot-sync'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="jp_field_shared"><?php esc_html_e('ACF field — shared profit', 'jackpot-sync'); ?></label></th>
                        <td><input type="text" id="jp_field_shared" name="jackpot_sync_settings[field_shared]"
                                   value="<?php echo esc_attr($s['field_shared']); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Default: shared_profit_amount', 'jackpot-sync'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Value format', 'jackpot-sync'); ?></th>
                        <td>
                            <label><input type="radio" name="jackpot_sync_settings[divisor]" value="1"
                                <?php checked($s['divisor'], 1); ?>> <?php esc_html_e('Whole units (use the number as-is)', 'jackpot-sync'); ?></label><br>
                            <label><input type="radio" name="jackpot_sync_settings[divisor]" value="100"
                                <?php checked($s['divisor'], 100); ?>> <?php esc_html_e('Cents (divide by 100)', 'jackpot-sync'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Image matching mode', 'jackpot-sync'); ?></th>
                        <td>
                            <label><input type="radio" name="jackpot_sync_settings[image_matching_mode]" value="jp_name"
                                <?php checked($s['image_matching_mode'], 'jp_name'); ?>> <?php esc_html_e('Match by jpName', 'jackpot-sync'); ?></label><br>
                            <label><input type="radio" name="jackpot_sync_settings[image_matching_mode]" value="jp_id"
                                <?php checked($s['image_matching_mode'], 'jp_id'); ?>> <?php esc_html_e('Match by jpId', 'jackpot-sync'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="jp_max_keep"><?php esc_html_e('Keep newest', 'jackpot-sync'); ?></label></th>
                        <td><input type="number" id="jp_max_keep" name="jackpot_sync_settings[max_keep]"
                                   value="<?php echo esc_attr($s['max_keep']); ?>" min="1" class="small-text">
                            <?php esc_html_e('jackpots', 'jackpot-sync'); ?>
                            <p class="description"><?php esc_html_e('Older jackpots beyond this count are removed hourly. Default: 20.', 'jackpot-sync'); ?></p></td>
                    </tr>
                </table>
                <?php submit_button(__('Save settings', 'jackpot-sync')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Activity log page.
     *
     * @return void
     */
    public function render_log_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $log   = Jackpot_Sync_Logger::get_log();
        $clear = wp_nonce_url(admin_url('admin-post.php?action=jackpot_clear_log'), 'jackpot_sync_clear_log');
        ?>
        <div class="wrap jackpot-sync-wrap">
            <?php
            $this->render_header(
                self::PAGE_LOG,
                __('Recent plugin events and errors.', 'jackpot-sync')
            );
            ?>

            <p>
                <a href="<?php echo esc_url($clear); ?>" class="button"><?php esc_html_e('Clear log', 'jackpot-sync'); ?></a>
            </p>
            <table class="widefat striped" style="max-width:980px">
                <thead><tr><th style="width:160px"><?php esc_html_e('Time', 'jackpot-sync'); ?></th><th><?php esc_html_e('Event', 'jackpot-sync'); ?></th></tr></thead>
                <tbody>
                <?php if (empty($log)) : ?>
                    <tr><td colspan="2"><em><?php esc_html_e('No events yet.', 'jackpot-sync'); ?></em></td></tr>
                <?php else : foreach ($log as $entry) : ?>
                    <tr>
                        <td><?php echo esc_html($entry['time']); ?></td>
                        <td>
                            <?php echo esc_html($entry['msg']); ?>
                            <?php if (!empty($entry['context']) && is_array($entry['context'])) : ?>
                                <br><code style="font-size:11px"><?php echo esc_html(wp_json_encode($entry['context'])); ?></code>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Message tester page.
     *
     * @return void
     */
    public function render_tester_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $key    = 'jackpot_sync_test_result_' . get_current_user_id();
        $result = get_transient($key);
        if ($result) {
            delete_transient($key);
        }
        ?>
        <div class="wrap jackpot-sync-wrap">
            <?php
            $this->render_header(
                self::PAGE_TESTER,
                __('Process one raw DRGT line with the same logic as the REST endpoint.', 'jackpot-sync')
            );
            ?>

            <?php if (!empty($result)) : ?>
                <div class="notice <?php echo !empty($result['ok']) ? 'notice-success' : 'notice-error'; ?>">
                    <p>
                        <strong><?php esc_html_e('Status:', 'jackpot-sync'); ?></strong> <?php echo esc_html((string) $result['status']); ?><br>
                        <strong><?php esc_html_e('Result:', 'jackpot-sync'); ?></strong> <?php echo esc_html($result['message']); ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('jackpot_sync_test_message'); ?>
                <input type="hidden" name="action" value="jackpot_process_test_message">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="jackpot_test_message"><?php esc_html_e('Message', 'jackpot-sync'); ?></label></th>
                        <td>
                            <textarea id="jackpot_test_message" name="jackpot_test_message" rows="4" class="large-text code"
                                      placeholder="JPCONFIG;O136;2;11;Huff n Puff Mystery LVL2;;IFCO"></textarea>
                            <p class="description"><?php esc_html_e('Supported:', 'jackpot-sync'); ?><br>
                                <code>JPCONFIG;&lt;jpId&gt;;&lt;level&gt;;&lt;jpType&gt;;&lt;jpName&gt;;&lt;prizeName&gt;;&lt;CasID&gt;</code><br>
                                <code>JPUPDATE;&lt;jpId&gt;;&lt;level&gt;;&lt;_&gt;;&lt;_&gt;;&lt;jpValue&gt;;&lt;jpShared&gt;;&lt;_&gt;;&lt;CasID&gt;</code>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Process', 'jackpot-sync')); ?>
            </form>
        </div>
        <?php
    }
}
