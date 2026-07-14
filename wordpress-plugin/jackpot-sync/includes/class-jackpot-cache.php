<?php
/**
 * Cache service.
 *
 * Purges the caching layers commonly present on the target sites after a
 * jackpot changes: WordPress object cache, the post cache, expired transients,
 * and LiteSpeed (including ESI blocks).
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coordinates cache invalidation across supported layers.
 */
class Jackpot_Sync_Cache {

    /**
     * Purge caches related to a single jackpot post.
     *
     * @param int $post_id Post ID.
     * @return void
     */
    public function purge($post_id) {
        $post_id = (int) $post_id;

        // Core object/post cache for this post.
        clean_post_cache($post_id);
        wp_cache_delete($post_id, 'posts');
        wp_cache_delete($post_id, 'post_meta');

        // Expired transients (safe, inexpensive housekeeping).
        if (function_exists('delete_expired_transients')) {
            delete_expired_transients(true);
        }

        // LiteSpeed cache: purge this specific post and its ESI blocks.
        do_action('litespeed_purge_post', $post_id);

        // Broader LiteSpeed hooks (no-op if the plugin is absent).
        do_action('litespeed_purge', 'post_' . $post_id);

        /**
         * Fires after Jackpot Sync purges caches for a post, so site-specific
         * integrations (e.g. custom object caches) can hook in.
         *
         * @param int $post_id The jackpot post ID.
         */
        do_action('jackpot_sync_after_purge', $post_id);
    }

    /**
     * Flush the entire object cache. Used sparingly (e.g. after retention).
     *
     * @return void
     */
    public function flush_all() {
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        do_action('litespeed_purge_all');
    }
}
