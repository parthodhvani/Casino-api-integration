<?php
/**
 * Jackpot creator service (handles JPCONFIG).
 *
 * Creates a jackpot post when one does not already exist for the given jpId,
 * stores identity metadata, and assigns a featured image resolved from the
 * local Media Library. Never fails because of a missing image.
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Creates jackpots from JPCONFIG payloads.
 */
class Jackpot_Sync_Creator {

    /** @var Jackpot_Sync_Repository */
    private $repository;

    /** @var Jackpot_Sync_Image_Resolver */
    private $image_resolver;

    /**
     * @param Jackpot_Sync_Repository     $repository     Data-access layer.
     * @param Jackpot_Sync_Image_Resolver $image_resolver Media resolver.
     */
    public function __construct(Jackpot_Sync_Repository $repository, Jackpot_Sync_Image_Resolver $image_resolver) {
        $this->repository     = $repository;
        $this->image_resolver = $image_resolver;
    }

    /**
     * Handle a normalized JPCONFIG payload.
     *
     * @param array<string,mixed> $data Normalized payload.
     * @return Jackpot_Sync_Result
     */
    public function handle(array $data) {
        $jp_id   = $data['jpId'];
        $jp_name = isset($data['jpName']) ? $data['jpName'] : '';

        $existing = $this->repository->find_by_jpid($jp_id);
        if ($existing) {
            Jackpot_Sync_Logger::increment('jackpots_skipped');
            return new Jackpot_Sync_Result(
                200,
                ['ok' => true, 'created' => false, 'id' => $existing],
                ['action' => 'skipped', 'skipped' => true]
            );
        }

        $title   = $jp_name !== '' ? $jp_name : 'Jackpot ' . $jp_id;
        $post_id = $this->repository->create($title);

        if (is_wp_error($post_id)) {
            Jackpot_Sync_Logger::increment('errors');
            return new Jackpot_Sync_Result(
                500,
                ['ok' => false, 'error' => 'insert failed'],
                ['action' => 'error', 'error' => 'Create failed for ' . $jp_id . ': ' . $post_id->get_error_message()]
            );
        }

        $this->repository->save_meta($post_id, $data);

        $attachment_id = $this->image_resolver->resolve($jp_name, $jp_id);
        $image_found   = $attachment_id > 0;

        if ($image_found) {
            set_post_thumbnail($post_id, $attachment_id);
            Jackpot_Sync_Logger::increment('images_found');
        } else {
            Jackpot_Sync_Logger::increment('images_missing');
            Jackpot_Sync_Logger::log('Image not found for jpId ' . $jp_id, ['jp_id' => $jp_id, 'jp_name' => $jp_name]);
        }

        Jackpot_Sync_Logger::increment('jackpots_created');
        Jackpot_Sync_Logger::update_stats(['last_config_time' => current_time('mysql')]);

        return new Jackpot_Sync_Result(
            201,
            ['ok' => true, 'created' => true, 'id' => $post_id, 'imageFound' => $image_found],
            [
                'action'        => 'created',
                'created'       => true,
                'image_found'   => $image_found,
                'image_missing' => !$image_found,
                'post_id'       => $post_id,
            ]
        );
    }
}
