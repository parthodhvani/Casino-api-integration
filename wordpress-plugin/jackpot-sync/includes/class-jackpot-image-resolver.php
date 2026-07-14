<?php
/**
 * Image resolver service.
 *
 * Finds an existing attachment in the local Media Library to use as the
 * jackpot featured image. Never downloads remote files and never uses
 * media_sideload_image(). If nothing is found it returns 0 and the caller
 * continues without failing.
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves featured images from the local Media Library only.
 */
class Jackpot_Sync_Image_Resolver {

    /**
     * File extensions searched when matching by jpId.
     *
     * @var string[]
     */
    private $extensions = ['webp', 'jpg', 'jpeg', 'png'];

    /**
     * Resolve an attachment ID for the given jackpot identity.
     *
     * Honors the configured matching mode, falling back to the other strategy
     * when the primary one finds nothing.
     *
     * @param string $jp_name Jackpot name (e.g. "Huff n Puff Mystery LVL2").
     * @param string $jp_id   Jackpot ID (e.g. "O136").
     * @return int Attachment ID or 0 when not found.
     */
    public function resolve($jp_name, $jp_id) {
        $mode = Jackpot_Sync_Settings::get('image_matching_mode');

        if ($mode === 'jp_id') {
            return $this->find_by_jp_id($jp_id) ?: $this->find_by_jp_name($jp_name);
        }

        return $this->find_by_jp_name($jp_name) ?: $this->find_by_jp_id($jp_id);
    }

    /**
     * Find an attachment whose title matches the jackpot name.
     *
     * Tries an exact title match first, then a broad search fallback.
     *
     * @param string $jp_name Jackpot name.
     * @return int Attachment ID or 0.
     */
    public function find_by_jp_name($jp_name) {
        $jp_name = trim((string) $jp_name);
        if ($jp_name === '') {
            return 0;
        }

        $exact = get_posts([
            'post_type'        => 'attachment',
            'post_status'      => 'inherit',
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'title'            => $jp_name,
            'suppress_filters' => true,
        ]);
        if (!empty($exact)) {
            return (int) $exact[0];
        }

        $fallback = get_posts([
            'post_type'        => 'attachment',
            'post_status'      => 'inherit',
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            's'                => $jp_name,
            'suppress_filters' => true,
        ]);

        return empty($fallback) ? 0 : (int) $fallback[0];
    }

    /**
     * Find an attachment whose filename is "<jpId>.<ext>".
     *
     * @param string $jp_id Jackpot ID.
     * @return int Attachment ID or 0.
     */
    public function find_by_jp_id($jp_id) {
        $jp_id = trim((string) $jp_id);
        if ($jp_id === '') {
            return 0;
        }

        // Case-insensitive candidate basenames.
        $candidates = array_unique([strtolower($jp_id), strtoupper($jp_id), $jp_id]);

        foreach ($candidates as $candidate) {
            foreach ($this->extensions as $extension) {
                $needle = '/' . $candidate . '.' . $extension;

                $query = new WP_Query([
                    'post_type'              => 'attachment',
                    'post_status'            => 'inherit',
                    'posts_per_page'         => 1,
                    'fields'                 => 'ids',
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                    'meta_query'             => [
                        [
                            'key'     => '_wp_attached_file',
                            'value'   => $needle,
                            'compare' => 'LIKE',
                        ],
                    ],
                ]);

                if ($query->have_posts()) {
                    return (int) $query->posts[0];
                }
            }
        }

        return 0;
    }
}
