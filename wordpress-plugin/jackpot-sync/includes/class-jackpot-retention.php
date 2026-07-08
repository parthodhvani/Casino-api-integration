<?php
/**
 * Retention service.
 *
 * Keeps only the newest N jackpots (by modified date) and deletes the rest on
 * an hourly cron. Works with WP-Cron and with a real server cron hitting
 * wp-cron.php.
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prunes old jackpots on a schedule.
 */
class Jackpot_Sync_Retention {

    const HOOK = 'jackpot_cleanup_event';

    /** @var Jackpot_Sync_Cache */
    private $cache;

    /**
     * @param Jackpot_Sync_Cache $cache Cache service.
     */
    public function __construct(Jackpot_Sync_Cache $cache) {
        $this->cache = $cache;
    }

    /**
     * Hook the cron callback.
     *
     * @return void
     */
    public function register() {
        add_action(self::HOOK, [$this, 'run']);
    }

    /**
     * Ensure the recurring event is scheduled.
     *
     * @return void
     */
    public static function schedule() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), 'hourly', self::HOOK);
        }
    }

    /**
     * Remove the scheduled event.
     *
     * @return void
     */
    public static function unschedule() {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Delete jackpots beyond the configured keep count.
     *
     * @return int Number of deleted posts.
     */
    public function run() {
        $keep = (int) Jackpot_Sync_Settings::get('max_keep');
        if ($keep < 1) {
            $keep = 20;
        }

        $old = get_posts([
            'post_type'      => Jackpot_Sync_Settings::get('cpt'),
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
            $this->cache->flush_all();
            Jackpot_Sync_Logger::log('Retention removed ' . count($old) . ' old jackpot(s), keeping newest ' . $keep . '.', [
                'deleted' => count($old),
                'kept'    => $keep,
            ]);
        }

        return count($old);
    }
}
