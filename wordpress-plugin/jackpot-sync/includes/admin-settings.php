<?php
/**
 * Admin settings page: Settings > Jackpot Sync.
 *
 * This is the whole configuration UI. After activating the plugin the user
 * lands here, pastes the shared secret, checks the green status boxes, and
 * copies the endpoint URL into the Cloudflare Worker.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* -- Menu ---------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_options_page(
        'Jackpot Sync',
        'Jackpot Sync',
        'manage_options',
        'jackpot-sync',
        'jackpot_render_settings_page'
    );

    add_management_page(
        'Jackpot Tester',
        'Jackpot Tester',
        'manage_options',
        'jackpot-sync-tester',
        'jackpot_render_tester_page'
    );
});

/* -- Register settings --------------------------------------------------- */

add_action('admin_init', function () {
    register_setting('jackpot_sync_group', 'jackpot_sync_settings', [
        'sanitize_callback' => 'jackpot_sanitize_settings',
        'default'           => jackpot_default_settings(),
    ]);
});

function jackpot_sanitize_settings($input) {
    $defaults = jackpot_default_settings();
    $out = [];

    $out['secret']       = isset($input['secret']) ? trim(sanitize_text_field($input['secret'])) : '';
    $out['cpt']          = !empty($input['cpt']) ? sanitize_key($input['cpt']) : $defaults['cpt'];
    $out['field_amount'] = !empty($input['field_amount']) ? sanitize_key($input['field_amount']) : $defaults['field_amount'];
    $out['field_shared'] = !empty($input['field_shared']) ? sanitize_key($input['field_shared']) : $defaults['field_shared'];
    $out['divisor']      = (isset($input['divisor']) && (int) $input['divisor'] === 100) ? 100 : 1;
    $out['max_keep']     = isset($input['max_keep']) ? max(1, (int) $input['max_keep']) : $defaults['max_keep'];
    $out['image_matching_mode'] = (isset($input['image_matching_mode']) && $input['image_matching_mode'] === 'jp_id') ? 'jp_id' : 'jp_name';

    return $out;
}

add_action('admin_post_jackpot_process_test_message', 'jackpot_process_test_message');

function jackpot_process_test_message() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'jackpot-sync'));
    }

    check_admin_referer('jackpot_sync_test_message');

    $raw = isset($_POST['jackpot_test_message']) ? wp_unslash($_POST['jackpot_test_message']) : '';
    $parsed = jackpot_parse_line_to_payload($raw);

    $result = [
        'ok'      => false,
        'message' => 'Unable to process message.',
        'status'  => 500,
    ];

    if (is_wp_error($parsed)) {
        $result['message'] = $parsed->get_error_message();
        $result['status']  = 400;
    } else {
        $response          = jackpot_process_payload($parsed, 'admin_tester');
        $body              = $response->get_data();
        $result['status']  = (int) $response->get_status();
        $result['ok']      = $result['status'] >= 200 && $result['status'] < 300;
        $result['message'] = is_array($body) ? wp_json_encode($body) : (string) $body;
    }

    set_transient('jackpot_sync_test_result_' . get_current_user_id(), $result, 120);
    wp_safe_redirect(admin_url('tools.php?page=jackpot-sync-tester'));
    exit;
}

/* -- Activation redirect (open settings automatically on activate) ------- */

add_action('admin_init', function () {
    if (get_transient('jackpot_sync_activated')) {
        delete_transient('jackpot_sync_activated');
        if (!isset($_GET['activate-multi'])) {
            wp_safe_redirect(admin_url('options-general.php?page=jackpot-sync'));
            exit;
        }
    }
});

/* -- Admin notice when not configured yet -------------------------------- */

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }
    // Don't nag on the settings page itself.
    $screen = get_current_screen();
    if ($screen && $screen->id === 'settings_page_jackpot-sync') {
        return;
    }
    if (empty(jackpot_get('secret'))) {
        $url = admin_url('options-general.php?page=jackpot-sync');
        echo '<div class="notice notice-warning is-dismissible"><p>'
           . '<strong>Jackpot Sync</strong> is active but not configured yet. '
           . '<a href="' . esc_url($url) . '">Add your shared secret</a> to start receiving jackpot updates.'
           . '</p></div>';
    }
});

/* -- Status checks ------------------------------------------------------- */

function jackpot_status_rows() {
    $secret   = jackpot_get('secret');
    $cpt      = jackpot_get('cpt');
    $acf      = function_exists('update_field');
    $cpt_ok   = post_type_exists($cpt);
    $constant = defined('JACKPOT_SECRET') && JACKPOT_SECRET !== '';

    return [
        [
            'label' => 'Shared secret',
            'ok'    => !empty($secret),
            'good'  => $constant ? 'Set via wp-config.php (JACKPOT_SECRET)' : 'Set on this page',
            'bad'   => 'Not set — paste the secret from your Cloudflare Worker below',
        ],
        [
            'label' => 'ACF active',
            'ok'    => $acf,
            'good'  => 'Advanced Custom Fields is active',
            'bad'   => 'update_field() not found — is ACF active?',
        ],
        [
            'label' => 'Custom Post Type',
            'ok'    => $cpt_ok,
            'good'  => 'CPT "' . esc_html($cpt) . '" exists',
            'bad'   => 'CPT "' . esc_html($cpt) . '" not found — check the slug below',
        ],
    ];
}

/* -- Page renderer ------------------------------------------------------- */

function jackpot_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $s        = wp_parse_args(get_option('jackpot_sync_settings', []), jackpot_default_settings());
    $constant = defined('JACKPOT_SECRET') && JACKPOT_SECRET !== '';
    $endpoint = jackpot_endpoint_url();
    $ping     = jackpot_ping_url();
    $log      = get_option('jackpot_sync_log', []);
    $stats    = jackpot_get_stats();
    ?>
    <div class="wrap">
        <h1>Jackpot Sync</h1>
        <p>This plugin receives live jackpot data from your Cloudflare Worker and
        writes it into the jackpot posts and ACF fields. Follow the three steps below.</p>

        <h2 class="title">Runtime overview</h2>
        <table class="widefat striped" style="max-width:760px">
            <tbody>
            <tr><td style="width:220px"><strong>Last MQTT Update</strong></td><td><?php echo esc_html($stats['last_update'] ?: '—'); ?></td></tr>
            <tr><td><strong>Last JPID</strong></td><td><?php echo esc_html($stats['last_jpid'] ?: '—'); ?></td></tr>
            <tr><td><strong>Jackpots Created</strong></td><td><?php echo esc_html((string) $stats['jackpots_created']); ?></td></tr>
            <tr><td><strong>Jackpots Updated</strong></td><td><?php echo esc_html((string) $stats['jackpots_updated']); ?></td></tr>
            <tr><td><strong>Jackpots Skipped</strong></td><td><?php echo esc_html((string) $stats['jackpots_skipped']); ?></td></tr>
            <tr><td><strong>Images Missing</strong></td><td><?php echo esc_html((string) $stats['images_missing']); ?></td></tr>
            <tr><td><strong>Last Error</strong></td><td><?php echo esc_html($stats['last_error'] ?: '—'); ?></td></tr>
            <tr><td><strong>Plugin Version</strong></td><td><?php echo esc_html(JACKPOT_SYNC_VERSION); ?></td></tr>
            <tr><td><strong>Worker Status (Last Request)</strong></td><td><?php echo esc_html($stats['worker_status']); ?></td></tr>
        </tbody>
        </table>

        <!-- STATUS -->
        <h2 class="title">Status</h2>
        <table class="widefat striped" style="max-width:760px">
            <tbody>
            <?php foreach (jackpot_status_rows() as $row): ?>
                <tr>
                    <td style="width:170px"><strong><?php echo esc_html($row['label']); ?></strong></td>
                    <td>
                        <?php if ($row['ok']): ?>
                            <span style="color:#1a7f37">&#10003; <?php echo esc_html($row['good']); ?></span>
                        <?php else: ?>
                            <span style="color:#b32d2e">&#10007; <?php echo esc_html($row['bad']); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- STEP 1: endpoint -->
        <h2 class="title">Step 1 — Copy this endpoint into your Cloudflare Worker</h2>
        <p>This is the URL the Worker sends jackpot updates to. Paste it into the
        Worker's <code>WP_SITES</code> configuration.</p>
        <input type="text" readonly onclick="this.select()" style="width:100%;max-width:760px;font-family:monospace"
               value="<?php echo esc_attr($endpoint); ?>">
        <p class="description">Health check (open in a browser, should return JSON):
            <a href="<?php echo esc_url($ping); ?>" target="_blank"><?php echo esc_html($ping); ?></a>
        </p>

        <!-- STEP 2: settings form -->
        <h2 class="title">Step 2 — Configure</h2>
        <form method="post" action="options.php">
            <?php settings_fields('jackpot_sync_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="jp_secret">Shared secret</label></th>
                    <td>
                        <?php if ($constant): ?>
                            <p><em>Locked — defined in <code>wp-config.php</code> as
                            <code>JACKPOT_SECRET</code>. This is the most secure option; leave it as is.</em></p>
                        <?php else: ?>
                            <input type="text" id="jp_secret" name="jackpot_sync_settings[secret]"
                                   value="<?php echo esc_attr($s['secret']); ?>"
                                   style="width:100%;max-width:760px;font-family:monospace">
                            <p class="description">Must be the <strong>exact same value</strong> as
                            <code>JACKPOT_SECRET</code> in your Cloudflare Worker. This is how WordPress
                            knows a request truly came from your Worker.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="jp_cpt">Jackpot post type slug</label></th>
                    <td>
                        <input type="text" id="jp_cpt" name="jackpot_sync_settings[cpt]"
                               value="<?php echo esc_attr($s['cpt']); ?>" class="regular-text">
                        <p class="description">The Custom Post Type where jackpots are stored. Default: <code>jackpot</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="jp_field_amount">ACF field — amount</label></th>
                    <td>
                        <input type="text" id="jp_field_amount" name="jackpot_sync_settings[field_amount]"
                               value="<?php echo esc_attr($s['field_amount']); ?>" class="regular-text">
                        <p class="description">Field name for the current jackpot value. Default: <code>amount</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="jp_field_shared">ACF field — shared profit</label></th>
                    <td>
                        <input type="text" id="jp_field_shared" name="jackpot_sync_settings[field_shared]"
                               value="<?php echo esc_attr($s['field_shared']); ?>" class="regular-text">
                        <p class="description">Field name for the shared pool value. Default: <code>shared_profit_amount</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Value format</th>
                    <td>
                        <label><input type="radio" name="jackpot_sync_settings[divisor]" value="1"
                            <?php checked($s['divisor'], 1); ?>> Whole euros (use the number as-is)</label><br>
                        <label><input type="radio" name="jackpot_sync_settings[divisor]" value="100"
                            <?php checked($s['divisor'], 100); ?>> Cents (divide by 100)</label>
                        <p class="description">If the feed sends <code>53467</code> and the real jackpot is
                        &euro;534.67, choose <strong>Cents</strong>. If it's &euro;53,467, choose <strong>Whole euros</strong>.
                        Verify against one live value before go-live.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Image matching mode</th>
                    <td>
                        <label><input type="radio" name="jackpot_sync_settings[image_matching_mode]" value="jp_name"
                            <?php checked($s['image_matching_mode'], 'jp_name'); ?>> Match by <code>jpName</code></label><br>
                        <label><input type="radio" name="jackpot_sync_settings[image_matching_mode]" value="jp_id"
                            <?php checked($s['image_matching_mode'], 'jp_id'); ?>> Match by <code>jpId</code></label>
                        <p class="description">Default is <strong>jpName</strong>. If no image is found the jackpot is still created and the event is logged.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="jp_max_keep">Keep newest</label></th>
                    <td>
                        <input type="number" id="jp_max_keep" name="jackpot_sync_settings[max_keep]"
                               value="<?php echo esc_attr($s['max_keep']); ?>" min="1" class="small-text"> jackpots
                        <p class="description">Older jackpots beyond this count are removed automatically (hourly). Default: 20.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save settings'); ?>
        </form>

        <!-- STEP 3: activity log -->
        <h2 class="title">Step 3 — Watch the activity log</h2>
        <p>Recent events (newest first). You should see entries appear when the Worker sends data.</p>
        <table class="widefat striped" style="max-width:760px">
            <thead><tr><th style="width:170px">Time</th><th>Event</th></tr></thead>
            <tbody>
            <?php if (empty($log)): ?>
                <tr><td colspan="2"><em>No events yet.</em></td></tr>
            <?php else: foreach ($log as $entry): ?>
                <tr>
                    <td><?php echo esc_html($entry['time']); ?></td>
                    <td>
                        <?php
                        echo esc_html($entry['msg']);
                        if (!empty($entry['context']) && is_array($entry['context'])) {
                            echo '<br><code>' . esc_html(wp_json_encode($entry['context'])) . '</code>';
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function jackpot_render_tester_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $result = get_transient('jackpot_sync_test_result_' . get_current_user_id());
    if ($result) {
        delete_transient('jackpot_sync_test_result_' . get_current_user_id());
    }
    ?>
    <div class="wrap">
        <h1>Jackpot Tester</h1>
        <p>Paste one raw message line and process it using the same logic as the REST endpoint.</p>
        <?php if (!empty($result)): ?>
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
                        <textarea id="jackpot_test_message" name="jackpot_test_message" rows="6" class="large-text code"
                                  placeholder="JPCONFIG;O136;2;11;Huff n Puff Mystery LVL2;;IFCO"></textarea>
                        <p class="description">Supported: <code>JPCONFIG;...</code> and <code>JPUPDATE;...</code></p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Process'); ?>
        </form>
    </div>
    <?php
}
