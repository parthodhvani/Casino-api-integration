<?php
/**
 * Admin UI.
 *
 * Renders the settings page (configuration + health + runtime metrics) and the
 * Tools -> Jackpot Tester page that runs a raw message through the exact same
 * processor as the REST endpoint.
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings + tester admin pages.
 */
class Jackpot_Sync_Admin {

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
        add_action('admin_post_jackpot_process_test_message', [$this, 'handle_test_message']);
        add_action('admin_post_jackpot_clear_log', [$this, 'handle_clear_log']);
        add_filter('plugin_action_links_' . JACKPOT_SYNC_BASENAME, [$this, 'add_settings_link']);
    }

    /**
     * Register admin menu entries.
     *
     * @return void
     */
    public function register_menus() {
        add_options_page('Jackpot Sync', 'Jackpot Sync', 'manage_options', 'jackpot-sync', [$this, 'render_settings_page']);
        add_management_page('Jackpot Tester', 'Jackpot Tester', 'manage_options', 'jackpot-sync-tester', [$this, 'render_tester_page']);
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
        $url = admin_url('options-general.php?page=jackpot-sync');
        array_unshift($links, '<a href="' . esc_url($url) . '">Settings</a>');
        return $links;
    }

    /**
     * Redirect to settings once, right after activation.
     *
     * @return void
     */
    public function maybe_activation_redirect() {
        if (get_transient('jackpot_sync_activated')) {
            delete_transient('jackpot_sync_activated');
            if (!isset($_GET['activate-multi'])) {
                wp_safe_redirect(admin_url('options-general.php?page=jackpot-sync'));
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
        if ($screen && $screen->id === 'settings_page_jackpot-sync') {
            return;
        }

        if (empty(Jackpot_Sync_Settings::get('secret'))) {
            $url = admin_url('options-general.php?page=jackpot-sync');
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . '<strong>Jackpot Sync</strong> is active but not configured yet. '
                . '<a href="' . esc_url($url) . '">Add your shared secret</a> to start receiving jackpot updates.'
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
        wp_safe_redirect(admin_url('tools.php?page=jackpot-sync-tester'));
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
        wp_safe_redirect(admin_url('options-general.php?page=jackpot-sync'));
        exit;
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
                'good'  => $constant ? 'Set via wp-config.php (JACKPOT_SECRET)' : 'Set on this page',
                'bad'   => 'Not set — paste the secret from your Cloudflare Worker below',
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
                'bad'   => 'CPT "' . esc_html($cpt) . '" not found — check the slug below',
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
     * Render the settings page.
     *
     * @return void
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $s        = Jackpot_Sync_Settings::all();
        $constant = Jackpot_Sync_Settings::secret_is_constant();
        $endpoint = Jackpot_Sync_Settings::endpoint_url();
        $ping     = Jackpot_Sync_Settings::ping_url();
        $log      = Jackpot_Sync_Logger::get_log();
        $stats    = Jackpot_Sync_Logger::get_stats();
        $clear    = wp_nonce_url(admin_url('admin-post.php?action=jackpot_clear_log'), 'jackpot_sync_clear_log');
        ?>
        <div class="wrap">
            <h1>Jackpot Sync <span style="font-size:13px;color:#666">v<?php echo esc_html(JACKPOT_SYNC_VERSION); ?></span></h1>
            <p>This plugin receives live jackpot data from your Cloudflare Worker and
            writes it into the jackpot posts and ACF fields.</p>

            <h2 class="title">Runtime overview</h2>
            <table class="widefat striped" style="max-width:820px">
                <tbody>
                <tr><td style="width:230px"><strong>Last MQTT Update</strong></td><td><?php echo esc_html($stats['last_update'] ?: '—'); ?></td></tr>
                <tr><td><strong>Last JPID</strong></td><td><?php echo esc_html($stats['last_jpid'] ?: '—'); ?> <?php echo $stats['last_message_type'] ? '(' . esc_html($stats['last_message_type']) . ')' : ''; ?></td></tr>
                <tr><td><strong>Last Casino ID</strong></td><td><?php echo esc_html($stats['last_casino_id'] ?: '—'); ?></td></tr>
                <tr><td><strong>Last JPCONFIG</strong></td><td><?php echo esc_html($stats['last_config_time'] ?: '—'); ?></td></tr>
                <tr><td><strong>Last JPUPDATE</strong></td><td><?php echo esc_html($stats['last_update_time'] ?: '—'); ?></td></tr>
                <tr><td><strong>Jackpots Created</strong></td><td><?php echo esc_html((string) $stats['jackpots_created']); ?></td></tr>
                <tr><td><strong>Jackpots Updated</strong></td><td><?php echo esc_html((string) $stats['jackpots_updated']); ?></td></tr>
                <tr><td><strong>Jackpots Skipped</strong></td><td><?php echo esc_html((string) $stats['jackpots_skipped']); ?></td></tr>
                <tr><td><strong>Images Found</strong></td><td><?php echo esc_html((string) $stats['images_found']); ?></td></tr>
                <tr><td><strong>Images Missing</strong></td><td><?php echo esc_html((string) $stats['images_missing']); ?></td></tr>
                <tr><td><strong>Errors</strong></td><td><?php echo esc_html((string) $stats['errors']); ?></td></tr>
                <tr><td><strong>Last Error</strong></td><td><?php echo esc_html($stats['last_error'] ?: '—'); ?></td></tr>
                <tr><td><strong>Last Execution Time</strong></td><td><?php echo esc_html((string) $stats['last_execution_ms']); ?> ms</td></tr>
                <tr><td><strong>Worker Status (Last Request)</strong></td><td><?php echo esc_html($stats['worker_status']); ?> <?php echo $stats['last_http_code'] ? '(HTTP ' . esc_html((string) $stats['last_http_code']) . ')' : ''; ?></td></tr>
                <tr><td><strong>Plugin Version</strong></td><td><?php echo esc_html(JACKPOT_SYNC_VERSION); ?></td></tr>
                </tbody>
            </table>

            <h2 class="title">Health</h2>
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

            <h2 class="title">Endpoint</h2>
            <p>Paste this URL into the Worker's <code>WP_SITES</code> configuration:</p>
            <input type="text" readonly onclick="this.select()" style="width:100%;max-width:820px;font-family:monospace"
                   value="<?php echo esc_attr($endpoint); ?>">
            <p class="description">Health check:
                <a href="<?php echo esc_url($ping); ?>" target="_blank" rel="noreferrer"><?php echo esc_html($ping); ?></a>
            </p>

            <h2 class="title">Configuration</h2>
            <form method="post" action="options.php">
                <?php settings_fields('jackpot_sync_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="jp_secret">Shared secret</label></th>
                        <td>
                            <?php if ($constant) : ?>
                                <p><em>Locked — defined in <code>wp-config.php</code> as <code>JACKPOT_SECRET</code>.</em></p>
                            <?php else : ?>
                                <input type="text" id="jp_secret" name="jackpot_sync_settings[secret]"
                                       value="<?php echo esc_attr($s['secret']); ?>"
                                       style="width:100%;max-width:820px;font-family:monospace">
                                <p class="description">Must exactly match <code>JACKPOT_SECRET</code> in your Cloudflare Worker.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="jp_cpt">Jackpot post type slug</label></th>
                        <td><input type="text" id="jp_cpt" name="jackpot_sync_settings[cpt]"
                                   value="<?php echo esc_attr($s['cpt']); ?>" class="regular-text">
                            <p class="description">Default: <code>jackpot</code>.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="jp_field_amount">ACF field — amount</label></th>
                        <td><input type="text" id="jp_field_amount" name="jackpot_sync_settings[field_amount]"
                                   value="<?php echo esc_attr($s['field_amount']); ?>" class="regular-text">
                            <p class="description">Default: <code>amount</code>.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="jp_field_shared">ACF field — shared profit</label></th>
                        <td><input type="text" id="jp_field_shared" name="jackpot_sync_settings[field_shared]"
                                   value="<?php echo esc_attr($s['field_shared']); ?>" class="regular-text">
                            <p class="description">Default: <code>shared_profit_amount</code>.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Value format</th>
                        <td>
                            <label><input type="radio" name="jackpot_sync_settings[divisor]" value="1"
                                <?php checked($s['divisor'], 1); ?>> Whole units (use the number as-is)</label><br>
                            <label><input type="radio" name="jackpot_sync_settings[divisor]" value="100"
                                <?php checked($s['divisor'], 100); ?>> Cents (divide by 100)</label>
                            <p class="description">Feed sends <code>53467</code>; if the real jackpot is &euro;534.67 choose <strong>Cents</strong>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Image matching mode</th>
                        <td>
                            <label><input type="radio" name="jackpot_sync_settings[image_matching_mode]" value="jp_name"
                                <?php checked($s['image_matching_mode'], 'jp_name'); ?>> Match by <code>jpName</code></label><br>
                            <label><input type="radio" name="jackpot_sync_settings[image_matching_mode]" value="jp_id"
                                <?php checked($s['image_matching_mode'], 'jp_id'); ?>> Match by <code>jpId</code></label>
                            <p class="description">Default <strong>jpName</strong>. If no image is found the jackpot is still created and logged.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="jp_max_keep">Keep newest</label></th>
                        <td><input type="number" id="jp_max_keep" name="jackpot_sync_settings[max_keep]"
                                   value="<?php echo esc_attr($s['max_keep']); ?>" min="1" class="small-text"> jackpots
                            <p class="description">Older jackpots beyond this count are removed hourly. Default: 20.</p></td>
                    </tr>
                </table>
                <?php submit_button('Save settings'); ?>
            </form>

            <h2 class="title">Activity log
                <a href="<?php echo esc_url($clear); ?>" class="button button-small" style="margin-left:8px">Clear log</a>
            </h2>
            <table class="widefat striped" style="max-width:980px">
                <thead><tr><th style="width:160px">Time</th><th>Event</th></tr></thead>
                <tbody>
                <?php if (empty($log)) : ?>
                    <tr><td colspan="2"><em>No events yet.</em></td></tr>
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
     * Render the Tools -> Jackpot Tester page.
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
        <div class="wrap">
            <h1>Jackpot Tester</h1>
            <p>Paste one raw DRGT message line and process it using the exact same
            logic as the REST endpoint — no MQTT publishing required.</p>

            <?php if (!empty($result)) : ?>
                <div class="notice <?php echo !empty($result['ok']) ? 'notice-success' : 'notice-error'; ?>">
                    <p>
                        <strong>Status:</strong> <?php echo esc_html((string) $result['status']); ?><br>
                        <strong>Result:</strong> <?php echo esc_html($result['message']); ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('jackpot_sync_test_message'); ?>
                <input type="hidden" name="action" value="jackpot_process_test_message">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="jackpot_test_message">Message</label></th>
                        <td>
                            <textarea id="jackpot_test_message" name="jackpot_test_message" rows="4" class="large-text code"
                                      placeholder="JPCONFIG;O136;2;11;Huff n Puff Mystery LVL2;;IFCO"></textarea>
                            <p class="description">Supported:<br>
                                <code>JPCONFIG;&lt;jpId&gt;;&lt;level&gt;;&lt;jpType&gt;;&lt;jpName&gt;;&lt;prizeName&gt;;&lt;CasID&gt;</code><br>
                                <code>JPUPDATE;&lt;jpId&gt;;&lt;level&gt;;&lt;_&gt;;&lt;_&gt;;&lt;jpValue&gt;;&lt;jpShared&gt;;&lt;_&gt;;&lt;CasID&gt;</code>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Process'); ?>
            </form>
        </div>
        <?php
    }
}
