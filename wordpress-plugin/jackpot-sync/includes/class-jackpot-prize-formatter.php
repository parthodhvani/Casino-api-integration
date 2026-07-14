<?php
/**
 * Prize value formatter service.
 *
 * Single place that converts upstream integer values into the numeric value
 * stored in ACF, honoring the configurable divisor (whole units vs cents).
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formats raw prize values according to plugin settings.
 */
class Jackpot_Sync_Prize_Formatter {

    /**
     * Convert a raw upstream value into the stored numeric value.
     *
     * Example: raw 53467 with divisor 100 -> 534.67; with divisor 1 -> 53467.
     *
     * @param mixed $raw Raw numeric value.
     * @return float
     */
    public function format($raw) {
        $value   = is_numeric($raw) ? (float) $raw : 0.0;
        $divisor = (int) Jackpot_Sync_Settings::get('divisor');

        if ($divisor === 100) {
            $value = $value / 100;
        }

        return $value;
    }
}
