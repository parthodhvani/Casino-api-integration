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

/* -- Lookup by unique key (jpId) ------------------------------------------ */

function jackpot_find_post_by_jpid($jp_id) {
    $jp_id = (string) $jp_id;

    $q = new WP_Query([
        'post_type'              => jackpot_get('cpt'),
        'post_status'            => 'any',
        'posts_per_page'         => 1,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_term_cache' => false,
        'meta_query'             => [
            'relation' => 'OR',
            ['key' => 'jp_id',           'value' => $jp_id],
            ['key' => '_jackpot_jpId',   'value' => $jp_id],
        ],
    ]);

    return $q->have_posts() ? (int) $q->posts[0] : 0;
}

/* -- Router -------------------------------------------------------------- */

function jackpot_handle_update(WP_REST_Request $req) {
    $data = $req->get_json_params();

    if (!is_array($data)) {
        jackpot_update_stats([
            'last_update'   => current_time('mysql'),
            'last_error'    => 'Invalid JSON payload',
            'worker_status' => 'Invalid request payload',
            'last_http_code'=> 400,
        ]);
        return new WP_REST_Response(['error' => 'invalid json'], 400);
    }

    return jackpot_process_payload($data, 'rest');
}

/**
 * Shared payload processor used by REST and the admin test tool.
 */
function jackpot_process_payload(array $data, $source = 'rest') {
    $started_at = microtime(true);
    $type       = isset($data['type']) ? strtoupper(sanitize_text_field($data['type'])) : '';
    $jp_id      = isset($data['jpId']) ? sanitize_text_field($data['jpId']) : '';
    $cas_id     = isset($data['casId']) ? sanitize_text_field($data['casId']) : '';

    if ($jp_id === '') {
        return jackpot_finish_request(
            new WP_REST_Response(['error' => 'missing jpId'], 400),
            $started_at,
            [
                'message_type' => $type,
                'jp_id'        => $jp_id,
                'cas_id'       => $cas_id,
                'source'       => $source,
                'error'        => 'missing jpId',
            ]
        );
    }

    if ($cas_id === '') {
        return jackpot_finish_request(
            new WP_REST_Response(['error' => 'missing casId'], 400),
            $started_at,
            [
                'message_type' => $type,
                'jp_id'        => $jp_id,
                'cas_id'       => $cas_id,
                'source'       => $source,
                'error'        => 'missing casId',
            ]
        );
    }

    if ($type === 'JPCONFIG') {
        if (empty($data['jpName'])) {
            return jackpot_finish_request(
                new WP_REST_Response(['error' => 'missing jpName for JPCONFIG'], 400),
                $started_at,
                [
                    'message_type' => $type,
                    'jp_id'        => $jp_id,
                    'cas_id'       => $cas_id,
                    'source'       => $source,
                    'error'        => 'missing jpName',
                ]
            );
        }

        $result = jackpot_handle_config($data, $jp_id, $cas_id);
        return jackpot_finish_request($result['response'], $started_at, array_merge($result['context'], [
            'message_type' => $type,
            'jp_id'        => $jp_id,
            'cas_id'       => $cas_id,
            'source'       => $source,
        ]));
    }

    if ($type === 'JPUPDATE') {
        if (!isset($data['jpValue']) || !isset($data['jpShared'])) {
            return jackpot_finish_request(
                new WP_REST_Response(['error' => 'missing jpValue or jpShared for JPUPDATE'], 400),
                $started_at,
                [
                    'message_type' => $type,
                    'jp_id'        => $jp_id,
                    'cas_id'       => $cas_id,
                    'source'       => $source,
                    'error'        => 'missing update values',
                ]
            );
        }

        $result = jackpot_handle_value_update($data, $jp_id, $cas_id);
        return jackpot_finish_request($result['response'], $started_at, array_merge($result['context'], [
            'message_type' => $type,
            'jp_id'        => $jp_id,
            'cas_id'       => $cas_id,
            'source'       => $source,
        ]));
    }

    return jackpot_finish_request(
        new WP_REST_Response(['error' => 'unknown type: ' . $type], 400),
        $started_at,
        [
            'message_type' => $type,
            'jp_id'        => $jp_id,
            'cas_id'       => $cas_id,
            'source'       => $source,
            'error'        => 'unknown type',
        ]
    );
}

/**
 * Finalize request bookkeeping and logging.
 */
function jackpot_finish_request(WP_REST_Response $response, $started_at, array $context) {
    $elapsed_ms = (int) round((microtime(true) - $started_at) * 1000);
    $http_code  = (int) $response->get_status();
    $error_msg  = isset($context['error']) ? (string) $context['error'] : '';

    $stats_updates = [
        'last_update'       => current_time('mysql'),
        'last_jpid'         => isset($context['jp_id']) ? (string) $context['jp_id'] : '',
        'last_message_type' => isset($context['message_type']) ? (string) $context['message_type'] : '',
        'last_execution_ms' => $elapsed_ms,
        'last_http_code'    => $http_code,
        'last_casino_id'    => isset($context['cas_id']) ? (string) $context['cas_id'] : '',
        'worker_status'     => ($http_code >= 200 && $http_code < 300) ? 'Healthy (last request succeeded)' : 'Error on last request',
    ];

    if ($error_msg !== '') {
        $stats_updates['last_error'] = $error_msg;
    } elseif ($http_code >= 200 && $http_code < 300) {
        $stats_updates['last_error'] = '';
    }

    jackpot_update_stats($stats_updates);

    $log_context                    = $context;
    $log_context['execution_ms']    = $elapsed_ms;
    $log_context['http_response']   = $http_code;
    $log_context['timestamp']       = current_time('mysql');
    $log_context['created']         = !empty($context['created']);
    $log_context['updated']         = !empty($context['updated']);
    $log_context['skipped']         = !empty($context['skipped']);
    $log_context['image_found']     = !empty($context['image_found']);
    $log_context['image_missing']   = !empty($context['image_missing']);
    $log_context['error']           = $error_msg;

    jackpot_log('Request processed', $log_context);

    return $response;
}

/* -- JPCONFIG: create the jackpot post if missing ------------------------ */

function jackpot_handle_config(array $data, $jp_id, $cas_id) {
    $post_id = jackpot_find_post_by_jpid($jp_id);

    if ($post_id) {
        jackpot_increment_stat('jackpots_skipped');
        return [
            'response' => new WP_REST_Response(['ok' => true, 'created' => false, 'id' => $post_id], 200),
            'context'  => [
                'created' => false,
                'updated' => false,
                'skipped' => true,
            ],
        ];
    }

    $title = !empty($data['jpName']) ? sanitize_text_field($data['jpName']) : 'Jackpot ' . $jp_id;

    $post_id = wp_insert_post([
        'post_type'   => jackpot_get('cpt'),
        'post_status' => 'publish',
        'post_title'  => $title,
    ], true);

    if (is_wp_error($post_id)) {
        return [
            'response' => new WP_REST_Response(['error' => 'insert failed'], 500),
            'context'  => [
                'error'   => 'Create failed for ' . $jp_id . ': ' . $post_id->get_error_message(),
                'created' => false,
            ],
        ];
    }

    update_post_meta($post_id, 'jp_id', $jp_id);
    update_post_meta($post_id, 'cas_id', $cas_id);
    update_post_meta($post_id, 'jpId', $jp_id);
    update_post_meta($post_id, 'casId', $cas_id);
    update_post_meta($post_id, 'level', isset($data['level']) ? sanitize_text_field($data['level']) : '');
    update_post_meta($post_id, 'jpType', isset($data['jpType']) ? sanitize_text_field($data['jpType']) : '');
    update_post_meta($post_id, 'prizeName', isset($data['prizeName']) ? sanitize_text_field($data['prizeName']) : '');
    update_post_meta($post_id, '_jackpot_jpId', $jp_id);
    update_post_meta($post_id, '_jackpot_casId', $cas_id);
    update_post_meta($post_id, '_jackpot_level', isset($data['level']) ? sanitize_text_field($data['level']) : '');
    update_post_meta($post_id, '_jackpot_jpType', isset($data['jpType']) ? sanitize_text_field($data['jpType']) : '');
    update_post_meta($post_id, '_jackpot_prizeName', isset($data['prizeName']) ? sanitize_text_field($data['prizeName']) : '');

    $resolver      = new Jackpot_Sync_Image_Resolver();
    $attachment_id = $resolver->resolve_attachment_id(isset($data['jpName']) ? $data['jpName'] : '', $jp_id);
    $image_found   = false;

    if ($attachment_id > 0) {
        set_post_thumbnail($post_id, $attachment_id);
        $image_found = true;
        jackpot_increment_stat('images_found');
    } else {
        jackpot_increment_stat('images_missing');
    }

    jackpot_increment_stat('jackpots_created');

    if (!$image_found) {
        jackpot_log('Image not found for jackpot create', ['jp_id' => $jp_id]);
    }

    return [
        'response' => new WP_REST_Response(['ok' => true, 'created' => true, 'id' => $post_id], 201),
        'context'  => [
            'created'       => true,
            'updated'       => false,
            'skipped'       => false,
            'image_found'   => $image_found,
            'image_missing' => !$image_found,
        ],
    ];
}

/* -- JPUPDATE: update ACF values ----------------------------------------- */

function jackpot_handle_value_update(array $data, $jp_id, $cas_id) {
    $post_id = jackpot_find_post_by_jpid($jp_id);

    if (!$post_id) {
        jackpot_increment_stat('jackpots_skipped');
        return [
            'response' => new WP_REST_Response(['error' => 'jackpot not found (waiting for JPCONFIG)'], 202),
            'context'  => [
                'error'   => 'Update skipped: jackpot not found for jpId ' . $jp_id,
                'created' => false,
                'updated' => false,
                'skipped' => true,
            ],
        ];
    }

    $formatter = new Jackpot_Sync_Prize_Formatter();
    $jp_value  = $formatter->format(isset($data['jpValue']) ? $data['jpValue'] : 0);
    $jp_shared = $formatter->format(isset($data['jpShared']) ? $data['jpShared'] : 0);

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
    jackpot_increment_stat('jackpots_updated');

    return [
        'response' => new WP_REST_Response([
            'ok'       => true,
            'id'       => $post_id,
            'jpValue'  => $jp_value,
            'jpShared' => $jp_shared,
        ], 200),
        'context'  => [
            'created' => false,
            'updated' => true,
            'skipped' => false,
        ],
    ];
}

/* -- Cache purge --------------------------------------------------------- */

function jackpot_purge_cache($post_id) {
    clean_post_cache($post_id);
    wp_cache_delete($post_id, 'posts');
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    if (function_exists('delete_expired_transients')) {
        delete_expired_transients(true);
    }

    // LiteSpeed cache: purge this specific post.
    do_action('litespeed_purge_post', $post_id);
}
