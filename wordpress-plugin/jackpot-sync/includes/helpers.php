<?php
/**
 * Backward-compatibility helper shim.
 *
 * The plugin was refactored into service classes in v3.0.0. These thin wrapper
 * functions preserve the public procedural API used by earlier versions (and
 * any site snippets that may call them) by delegating to the new services.
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('jackpot_default_settings')) {
    /**
     * @return array<string,mixed>
     */
    function jackpot_default_settings() {
        return Jackpot_Sync_Settings::defaults();
    }
}

if (!function_exists('jackpot_get')) {
    /**
     * @param string $key Setting key.
     * @return mixed|null
     */
    function jackpot_get($key) {
        return Jackpot_Sync_Settings::get($key);
    }
}

if (!function_exists('jackpot_endpoint_url')) {
    /**
     * @return string
     */
    function jackpot_endpoint_url() {
        return Jackpot_Sync_Settings::endpoint_url();
    }
}

if (!function_exists('jackpot_ping_url')) {
    /**
     * @return string
     */
    function jackpot_ping_url() {
        return Jackpot_Sync_Settings::ping_url();
    }
}

if (!function_exists('jackpot_log')) {
    /**
     * @param string              $message Message.
     * @param array<string,mixed> $context Context.
     * @return void
     */
    function jackpot_log($message, array $context = []) {
        Jackpot_Sync_Logger::log($message, $context);
    }
}

if (!function_exists('jackpot_purge_cache')) {
    /**
     * @param int $post_id Post ID.
     * @return void
     */
    function jackpot_purge_cache($post_id) {
        Jackpot_Sync_Plugin::instance()->cache()->purge($post_id);
    }
}

if (!function_exists('jackpot_find_post_by_jpid')) {
    /**
     * @param string $jp_id Jackpot ID.
     * @return int
     */
    function jackpot_find_post_by_jpid($jp_id) {
        return Jackpot_Sync_Plugin::instance()->repository()->find_by_jpid($jp_id);
    }
}
