<?php
/**
 * Small service classes used by REST/admin handlers.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formats incoming prize values based on plugin settings.
 */
class Jackpot_Sync_Prize_Formatter {
    /**
     * @param mixed $raw Raw upstream numeric value.
     * @return float
     */
    public function format($raw) {
        $value   = (float) $raw;
        $divisor = (int) jackpot_get('divisor');

        if ($divisor === 100) {
            $value = $value / 100;
        }

        return $value;
    }
}

/**
 * Resolves local attachments for jackpot featured images.
 */
class Jackpot_Sync_Image_Resolver {
    /**
     * @param string $jp_name Jackpot name from JPCONFIG.
     * @param string $jp_id   Jackpot ID from JPCONFIG.
     * @return int Attachment post ID or 0 when not found.
     */
    public function resolve_attachment_id($jp_name, $jp_id) {
        $mode = jackpot_get('image_matching_mode');

        if ($mode === 'jp_id') {
            $attachment_id = $this->find_by_jp_id($jp_id);
            if ($attachment_id) {
                return $attachment_id;
            }

            return $this->find_by_jp_name($jp_name);
        }

        $attachment_id = $this->find_by_jp_name($jp_name);
        if ($attachment_id) {
            return $attachment_id;
        }

        return $this->find_by_jp_id($jp_id);
    }

    /**
     * Search attachments by title.
     */
    private function find_by_jp_name($jp_name) {
        $jp_name = trim((string) $jp_name);
        if ($jp_name === '') {
            return 0;
        }

        $exact = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'title'          => $jp_name,
        ]);
        if (!empty($exact)) {
            return (int) $exact[0];
        }

        $fallback = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            's'              => $jp_name,
        ]);

        return empty($fallback) ? 0 : (int) $fallback[0];
    }

    /**
     * Search attachments by canonical basename patterns.
     */
    private function find_by_jp_id($jp_id) {
        $jp_id = trim((string) $jp_id);
        if ($jp_id === '') {
            return 0;
        }

        $basename_candidates = [
            strtolower($jp_id),
            strtoupper($jp_id),
        ];
        $extensions = ['webp', 'jpg', 'jpeg', 'png'];

        foreach ($basename_candidates as $candidate) {
            foreach ($extensions as $extension) {
                $needle = $candidate . '.' . $extension;
                $query  = new WP_Query([
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_query'     => [
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
