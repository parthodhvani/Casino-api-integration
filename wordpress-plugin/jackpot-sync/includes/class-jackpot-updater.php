<?php
/**
 * Jackpot updater service (handles JPUPDATE).
 *
 * Finds a jackpot by jpId and updates only the two ACF value fields, then
 * bumps the modified date and purges caches. Never touches the title, slug,
 * featured image or post status.
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Updates jackpot values from JPUPDATE payloads.
 */
class Jackpot_Sync_Updater {

    /** @var Jackpot_Sync_Repository */
    private $repository;

    /** @var Jackpot_Sync_Prize_Formatter */
    private $formatter;

    /** @var Jackpot_Sync_Cache */
    private $cache;

    /**
     * @param Jackpot_Sync_Repository      $repository Data-access layer.
     * @param Jackpot_Sync_Prize_Formatter $formatter  Value formatter.
     * @param Jackpot_Sync_Cache           $cache      Cache service.
     */
    public function __construct(
        Jackpot_Sync_Repository $repository,
        Jackpot_Sync_Prize_Formatter $formatter,
        Jackpot_Sync_Cache $cache
    ) {
        $this->repository = $repository;
        $this->formatter  = $formatter;
        $this->cache      = $cache;
    }

    /**
     * Handle a normalized JPUPDATE payload.
     *
     * @param array<string,mixed> $data Normalized payload.
     * @return Jackpot_Sync_Result
     */
    public function handle(array $data) {
        $jp_id   = $data['jpId'];
        $post_id = $this->repository->find_by_jpid($jp_id);

        if (!$post_id) {
            Jackpot_Sync_Logger::increment('jackpots_skipped');
            return new Jackpot_Sync_Result(
                202,
                ['ok' => false, 'error' => 'jackpot not found (waiting for JPCONFIG)'],
                [
                    'action'  => 'skipped',
                    'skipped' => true,
                    'error'   => 'Update skipped: no jackpot for jpId ' . $jp_id,
                ]
            );
        }

        $amount = $this->formatter->format(isset($data['jpValue']) ? $data['jpValue'] : 0);
        $shared = $this->formatter->format(isset($data['jpShared']) ? $data['jpShared'] : 0);

        $field_amount = Jackpot_Sync_Settings::get('field_amount');
        $field_shared = Jackpot_Sync_Settings::get('field_shared');

        if (function_exists('update_field')) {
            update_field($field_amount, $amount, $post_id);
            update_field($field_shared, $shared, $post_id);
        } else {
            update_post_meta($post_id, $field_amount, $amount);
            update_post_meta($post_id, $field_shared, $shared);
        }

        $this->repository->touch($post_id);
        $this->cache->purge($post_id);

        Jackpot_Sync_Logger::increment('jackpots_updated');
        Jackpot_Sync_Logger::update_stats(['last_update_time' => current_time('mysql')]);

        return new Jackpot_Sync_Result(
            200,
            ['ok' => true, 'id' => $post_id, 'amount' => $amount, 'sharedProfit' => $shared],
            ['action' => 'updated', 'updated' => true, 'post_id' => $post_id]
        );
    }
}
