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

    return $out;
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
    ?>
    <div class="wrap">
        <h1>Jackpot Sync</h1>
        <p>This plugin receives live jackpot data from your Cloudflare Worker and
        writes it into the jackpot posts and ACF fields. Follow the three steps below.</p>

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
                        <p class="description">Field name for the current jackpot value. Default: <code>jackpot_amount</code>.</p>
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
                    <td><?php echo esc_html($entry['msg']); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
