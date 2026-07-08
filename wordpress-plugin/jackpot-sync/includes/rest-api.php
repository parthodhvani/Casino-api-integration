<?php
/**
 * REST endpoint + message handlers.
 *
 *   POST /wp-json/jackpot/v1/update   (signed, does the work)
 *   GET  /wp-json/jackpot/v1/ping     (open, health check)
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('jackpot/v1', '/update', [
        'methods'             => 'POST',
        'callback'            => 'jackpot_handle_update',
        'permission_callback' => 'jackpot_verify_signature',
    ]);

    register_rest_route('jackpot/v1', '/ping', [
        'methods'             => 'GET',
        'callback'            => function () {
            return new WP_REST_Response([
                'ok'     => true,
                'plugin' => 'jackpot-sync',
                'secret' => !empty(jackpot_get('secret')),
            ], 200);
        },
        'permission_callback' => '__return_true',
    ]);
});

/* -- Security ------------------------------------------------------------ */

function jackpot_verify_signature(WP_REST_Request $req) {
    $secret = jackpot_get('secret');

    if (empty($secret)) {
        jackpot_log('Rejected: shared secret is not configured yet.');
        return false;
    }

    $got = $req->get_header('x-signature');
    if (!$got) {
        jackpot_log('Rejected: missing X-Signature header.');
        return false;
    }

    $expected = hash_hmac('sha256', $req->get_body(), $secret);
    if (!hash_equals($expected, $got)) {
        jackpot_log('Rejected: signature mismatch (wrong secret?).');
        return false;
    }

    return true;
}

/* -- Lookup by unique key (jpId + casId) --------------------------------- */

function jackpot_find_post($jp_id, $cas_id) {
    $q = new WP_Query([
        'post_type'              => jackpot_get('cpt'),
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

/* -- Router -------------------------------------------------------------- */

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

/* -- JPCONFIG: create the jackpot post if missing ------------------------ */

function jackpot_handle_config(array $data, $jp_id, $cas_id) {
    $post_id = jackpot_find_post($jp_id, $cas_id);

    if ($post_id) {
        return new WP_REST_Response(['ok' => true, 'created' => false, 'id' => $post_id], 200);
    }

    $title = !empty($data['jpName']) ? sanitize_text_field($data['jpName']) : 'Jackpot ' . $jp_id;

    $post_id = wp_insert_post([
        'post_type'   => jackpot_get('cpt'),
        'post_status' => 'publish',
        'post_title'  => $title,
    ], true);

    if (is_wp_error($post_id)) {
        jackpot_log('Create failed for ' . $jp_id . ': ' . $post_id->get_error_message());
        return new WP_REST_Response(['error' => 'insert failed'], 500);
    }

    update_post_meta($post_id, 'jp_id', $jp_id);
    update_post_meta($post_id, 'cas_id', $cas_id);

    if (!empty($data['imageUrl'])) {
        jackpot_sideload_image($post_id, esc_url_raw($data['imageUrl']));
    }

    jackpot_log('Created jackpot "' . $title . '" (' . $jp_id . '/' . $cas_id . ')');

    return new WP_REST_Response(['ok' => true, 'created' => true, 'id' => $post_id], 201);
}

/* -- JPUPDATE: update ACF values ----------------------------------------- */

function jackpot_handle_value_update(array $data, $jp_id, $cas_id) {
    $post_id = jackpot_find_post($jp_id, $cas_id);

    if (!$post_id) {
        jackpot_log('Update skipped: no jackpot yet for ' . $jp_id . '/' . $cas_id . ' (waiting for JPCONFIG).');
        return new WP_REST_Response(['error' => 'jackpot not found (waiting for JPCONFIG)'], 202);
    }

    $divisor   = (int) jackpot_get('divisor');
    $jp_value  = isset($data['jpValue'])  ? (float) $data['jpValue']  : 0;
    $jp_shared = isset($data['jpShared']) ? (float) $data['jpShared'] : 0;

    if ($divisor === 100) {
        $jp_value  = $jp_value / 100;
        $jp_shared = $jp_shared / 100;
    }

    $field_amount = jackpot_get('field_amount');
    $field_shared = jackpot_get('field_shared');

    if (function_exists('update_field')) {
        update_field($field_amount, $jp_value, $post_id);
        update_field($field_shared, $jp_shared, $post_id);
    } else {
        update_post_meta($post_id, $field_amount, $jp_value);
        update_post_meta($post_id, $field_shared, $jp_shared);
    }

    // Bump modified date so retention (orderby modified) stays accurate.
    wp_update_post([
        'ID'                => $post_id,
        'post_modified'     => current_time('mysql'),
        'post_modified_gmt' => current_time('mysql', 1),
    ]);

    jackpot_purge_cache($post_id);

    jackpot_log('Updated ' . $jp_id . ' -> value ' . $jp_value . ', shared ' . $jp_shared);

    return new WP_REST_Response([
        'ok'       => true,
        'id'       => $post_id,
        'jpValue'  => $jp_value,
        'jpShared' => $jp_shared,
    ], 200);
}

/* -- Cache purge --------------------------------------------------------- */

function jackpot_purge_cache($post_id) {
    clean_post_cache($post_id);
    wp_cache_delete($post_id, 'posts');

    // LiteSpeed cache: purge this specific post.
    do_action('litespeed_purge_post', $post_id);
}

/* -- Image sideload ------------------------------------------------------ */

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
