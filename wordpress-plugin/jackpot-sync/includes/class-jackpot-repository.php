<?php
/**
 * Jackpot repository.
 *
 * Encapsulates all data access for jackpot posts: lookup by jpId and reading
 * or writing post meta. The unique identifier is always jpId (never title,
 * amount or image).
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Data-access layer for jackpot posts.
 */
class Jackpot_Sync_Repository {

    /**
     * Meta key holding the canonical jpId.
     */
    const META_JPID = '_jackpot_jpId';

    /**
     * Legacy meta key kept for backward compatibility with earlier versions.
     */
    const LEGACY_JPID = 'jp_id';

    /**
     * Find a jackpot post by its jpId.
     *
     * Matches both the current hidden meta key and the legacy key so posts
     * created by older plugin versions continue to resolve.
     *
     * @param string $jp_id Jackpot ID.
     * @return int Post ID or 0 when not found.
     */
    public function find_by_jpid($jp_id) {
        $jp_id = (string) $jp_id;
        if ($jp_id === '') {
            return 0;
        }

        $query = new WP_Query([
            'post_type'              => Jackpot_Sync_Settings::get('cpt'),
            'post_status'            => 'any',
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'             => [
                'relation' => 'OR',
                ['key' => self::META_JPID, 'value' => $jp_id],
                ['key' => self::LEGACY_JPID, 'value' => $jp_id],
            ],
        ]);

        return $query->have_posts() ? (int) $query->posts[0] : 0;
    }

    /**
     * Create a published jackpot post.
     *
     * @param string $title Post title (jpName).
     * @return int|WP_Error New post ID or error.
     */
    public function create($title) {
        return wp_insert_post([
            'post_type'   => Jackpot_Sync_Settings::get('cpt'),
            'post_status' => 'publish',
            'post_title'  => $title,
        ], true);
    }

    /**
     * Persist the identity + descriptive metadata for a jackpot.
     *
     * Stores both hidden meta (canonical, underscore-prefixed) and legacy /
     * plain keys so front-end templates and older code keep working.
     *
     * @param int                 $post_id Post ID.
     * @param array<string,mixed> $data    Normalized JPCONFIG payload.
     * @return void
     */
    public function save_meta($post_id, array $data) {
        $fields = [
            'jpId'      => isset($data['jpId']) ? $data['jpId'] : '',
            'casId'     => isset($data['casId']) ? $data['casId'] : '',
            'level'     => isset($data['level']) ? $data['level'] : '',
            'jpType'    => isset($data['jpType']) ? $data['jpType'] : '',
            'prizeName' => isset($data['prizeName']) ? $data['prizeName'] : '',
            'jpName'    => isset($data['jpName']) ? $data['jpName'] : '',
        ];

        foreach ($fields as $key => $value) {
            $value = sanitize_text_field((string) $value);
            // Hidden (canonical) meta.
            update_post_meta($post_id, '_jackpot_' . $key, $value);
            // Plain meta for template/back-compat convenience.
            update_post_meta($post_id, $key, $value);
        }

        // Legacy keys used by the pre-3.0 implementation.
        update_post_meta($post_id, 'jp_id', $fields['jpId']);
        update_post_meta($post_id, 'cas_id', $fields['casId']);
    }

    /**
     * Bump the modified date so retention (orderby modified) stays accurate.
     *
     * @param int $post_id Post ID.
     * @return void
     */
    public function touch($post_id) {
        wp_update_post([
            'ID'                => $post_id,
            'post_modified'     => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1),
        ]);
    }
}
