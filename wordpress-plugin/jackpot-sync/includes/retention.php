<?php
/**
 * Retention: keep only the newest N jackpots, delete the rest (hourly cron).
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('jackpot_cleanup_event', 'jackpot_cleanup');

function jackpot_cleanup() {
    $keep = (int) jackpot_get('max_keep');
    if ($keep < 1) {
        $keep = 20;
    }

    $old = get_posts([
        'post_type'      => jackpot_get('cpt'),
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
        jackpot_log('Retention removed ' . count($old) . ' old jackpot(s), keeping newest ' . $keep . '.');
    }
}
