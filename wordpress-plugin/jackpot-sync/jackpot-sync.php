<?php
/**
 * Plugin Name: Jackpot Sync
 * Description: Receives real-time jackpot data (JPCONFIG / JPUPDATE) from the Cloudflare Worker and writes it to the Jackpot CPT + ACF fields.
 * Version:     1.0.0
 * Author:      Rise Web
 *
 * SECURITY:
 *   All incoming requests are verified with an HMAC-SHA256 signature.
 *   Define the shared secret in wp-config.php:
 *
 *       define('JACKPOT_SECRET', 'your-long-random-secret');
 *
 * CONFIG (optional overrides via wp-config.php):
 *       define('JACKPOT_CPT', 'jackpot');          // Custom Post Type slug
 *       define('JACKPOT_MAX_KEEP', 20);            // retention limit
 *       define('JACKPOT_VALUE_DIVISOR', 1);        // set to 100 if values arrive in cents
 */

if (!defined('ABSPATH')) {
    exit;
}

/* -------------------------------------------------------------------------
 * Configuration helpers
 * ---------------------------------------------------------------------- */

function jackpot_cpt() {
    return defined('JACKPOT_CPT') ? JACKPOT_CPT : 'jackpot';
}

function jackpot_max_keep() {
    return defined('JACKPOT_MAX_KEEP') ? (int) JACKPOT_MAX_KEEP : 20;
}

function jackpot_value_divisor() {
    return defined('JACKPOT_VALUE_DIVISOR') ? (int) JACKPOT_VALUE_DIVISOR : 1;
}

/* -------------------------------------------------------------------------
 * REST route registration
 * ---------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('jackpot/v1', '/update', [
        'methods'             => 'POST',
        'callback'            => 'jackpot_handle_update',
        'permission_callback' => 'jackpot_verify_signature',
    ]);

    // Simple health check (no secret required, returns 200 so you can verify routing/WAF).
    register_rest_route('jackpot/v1', '/ping', [
        'methods'             => 'GET',
        'callback'            => function () {
            return new WP_REST_Response(['ok' => true, 'plugin' => 'jackpot-sync'], 200);
        },
        'permission_callback' => '__return_true',
    ]);
});

/* -------------------------------------------------------------------------
 * Signature verification
 * ---------------------------------------------------------------------- */

function jackpot_verify_signature(WP_REST_Request $req) {
    if (!defined('JACKPOT_SECRET') || JACKPOT_SECRET === '') {
        jackpot_log('JACKPOT_SECRET is not defined in wp-config.php');
        return false;
    }

    $body     = $req->get_body();
    $expected = hash_hmac('sha256', $body, JACKPOT_SECRET);
    $got      = $req->get_header('x-signature');

    if (!$got) {
        jackpot_log('Missing X-Signature header');
        return false;
    }

    if (!hash_equals($expected, $got)) {
        jackpot_log('Signature mismatch');
        return false;
    }

    return true;
}

/* -------------------------------------------------------------------------
 * Post lookup by unique key (jpId + CasID)
 * ---------------------------------------------------------------------- */

function jackpot_find_post($jp_id, $cas_id) {
    $q = new WP_Query([
        'post_type'              => jackpot_cpt(),
        'post_status'            => 'any',
        'posts_per_page'         => 1,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_term_cache' => false,
        'meta_query'             => [
            'relation' => 'AND',
            ['key' => 'jp_id',  'value' => $jp_id],
            ['key' => 'cas_id', 'value' => $cas_id],
        ],
    ]);

    return $q->have_posts() ? (int) $q->posts[0] : 0;
}

/* -------------------------------------------------------------------------
 * Main handler
 * ---------------------------------------------------------------------- */

function jackpot_handle_update(WP_REST_Request $req) {
    $data = $req->get_json_params();

    if (!is_array($data)) {
        return new WP_REST_Response(['error' => 'invalid json'], 400);
    }

    $type   = isset($data['type'])  ? sanitize_text_field($data['type'])  : '';
    $jp_id  = isset($data['jpId'])  ? sanitize_text_field($data['jpId'])  : '';
    $cas_id = isset($data['casId']) ? sanitize_text_field($data['casId']) : '';

    if ($jp_id === '' || $cas_id === '') {
        return new WP_REST_Response(['error' => 'missing jpId or casId'], 400);
    }

    if ($type === 'JPCONFIG') {
        return jackpot_handle_config($data, $jp_id, $cas_id);
    }

    if ($type === 'JPUPDATE') {
        return jackpot_handle_value_update($data, $jp_id, $cas_id);
    }

    return new WP_REST_Response(['error' => 'unknown type: ' . $type], 400);
}

/* -------------------------------------------------------------------------
 * JPCONFIG: create the jackpot post if it does not exist
 * ---------------------------------------------------------------------- */

function jackpot_handle_config(array $data, $jp_id, $cas_id) {
    $post_id = jackpot_find_post($jp_id, $cas_id);

    if (!$post_id) {
        $title = isset($data['jpName']) && $data['jpName'] !== ''
            ? sanitize_text_field($data['jpName'])
            : 'Jackpot ' . $jp_id;

        $post_id = wp_insert_post([
            'post_type'   => jackpot_cpt(),
            'post_status' => 'publish',
            'post_title'  => $title,
        ], true);

        if (is_wp_error($post_id)) {
            jackpot_log('wp_insert_post failed: ' . $post_id->get_error_message());
            return new WP_REST_Response(['error' => 'insert failed'], 500);
        }

        update_post_meta($post_id, 'jp_id', $jp_id);
        update_post_meta($post_id, 'cas_id', $cas_id);

        // Optional: sideload the featured image if the payload includes an image URL.
        if (!empty($data['imageUrl'])) {
            jackpot_sideload_image($post_id, esc_url_raw($data['imageUrl']));
        }

        jackpot_log("Created jackpot post {$post_id} for {$jp_id}/{$cas_id}");
        jackpot_cleanup();
        return new WP_REST_Response(['ok' => true, 'created' => true, 'id' => $post_id], 201);
    }

    return new WP_REST_Response(['ok' => true, 'created' => false, 'id' => $post_id], 200);
}

/* -------------------------------------------------------------------------
 * JPUPDATE: update ACF values on an existing jackpot
 * ---------------------------------------------------------------------- */

function jackpot_handle_value_update(array $data, $jp_id, $cas_id) {
    $post_id = jackpot_find_post($jp_id, $cas_id);

    if (!$post_id) {
        // Config not seen yet. 202 = accepted but nothing done.
        jackpot_log("JPUPDATE for unknown jackpot {$jp_id}/{$cas_id} - waiting for JPCONFIG");
        return new WP_REST_Response(['error' => 'jackpot not found (waiting for JPCONFIG)'], 202);
    }

    $divisor = jackpot_value_divisor();

    $jp_value  = isset($data['jpValue'])  ? (float) $data['jpValue']  : 0;
    $jp_shared = isset($data['jpShared']) ? (float) $data['jpShared'] : 0;

    if ($divisor > 1) {
        $jp_value  = $jp_value / $divisor;
        $jp_shared = $jp_shared / $divisor;
    }

    if (function_exists('update_field')) {
        update_field('jackpot_amount', $jp_value, $post_id);
        update_field('shared_profit_amount', $jp_shared, $post_id);
    } else {
        // Fallback if ACF helper is unavailable.
        update_post_meta($post_id, 'jackpot_amount', $jp_value);
        update_post_meta($post_id, 'shared_profit_amount', $jp_shared);
    }

    // Bump modified date so retention "orderby modified" stays accurate.
    wp_update_post(['ID' => $post_id, 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', 1)]);

    jackpot_purge_cache($post_id);

    return new WP_REST_Response([
        'ok'       => true,
        'id'       => $post_id,
        'jpValue'  => $jp_value,
        'jpShared' => $jp_shared,
    ], 200);
}

/* -------------------------------------------------------------------------
 * Cache purge
 * ---------------------------------------------------------------------- */

function jackpot_purge_cache($post_id) {
    clean_post_cache($post_id);

    if (function_exists('wp_cache_flush')) {
        // Only flush object cache selectively where possible; full flush is a safe fallback.
        wp_cache_delete($post_id, 'posts');
    }

    // LiteSpeed cache purge for this specific post.
    do_action('litespeed_purge_post', $post_id);
}

/* -------------------------------------------------------------------------
 * Image sideload
 * ---------------------------------------------------------------------- */

function jackpot_sideload_image($post_id, $image_url) {
    if (!$image_url) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $attachment_id = media_sideload_image($image_url, $post_id, null, 'id');

    if (is_wp_error($attachment_id)) {
        jackpot_log('Image sideload failed: ' . $attachment_id->get_error_message());
        return;
    }

    set_post_thumbnail($post_id, $attachment_id);
}

/* -------------------------------------------------------------------------
 * Retention cron (keep newest N jackpots)
 * ---------------------------------------------------------------------- */

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('jackpot_cleanup_event')) {
        wp_schedule_event(time(), 'hourly', 'jackpot_cleanup_event');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('jackpot_cleanup_event');
});

add_action('jackpot_cleanup_event', 'jackpot_cleanup');

function jackpot_cleanup() {
    $keep = jackpot_max_keep();

    $old = get_posts([
        'post_type'      => jackpot_cpt(),
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'orderby'        => 'modified',
        'order'          => 'DESC',
        'fields'         => 'ids',
        'offset'         => $keep,
    ]);

    foreach ($old as $id) {
        wp_delete_post($id, true);
    }

    if (!empty($old)) {
        jackpot_log('Retention removed ' . count($old) . ' old jackpots');
    }
}

/* -------------------------------------------------------------------------
 * Logging (writes only when WP_DEBUG_LOG is enabled)
 * ---------------------------------------------------------------------- */

function jackpot_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[jackpot-sync] ' . $message);
    }
}
