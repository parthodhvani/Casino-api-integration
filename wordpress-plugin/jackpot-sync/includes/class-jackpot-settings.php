<?php
/**
 * Settings service.
 *
 * Central, typed access to plugin configuration. All other services read
 * configuration through this class so option names live in exactly one place.
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads and normalizes the plugin settings option.
 */
class Jackpot_Sync_Settings {

    /**
     * Option key that stores the settings array.
     */
    const OPTION = 'jackpot_sync_settings';

    /**
     * Default configuration values used on a fresh install.
     *
     * @return array<string,mixed>
     */
    public static function defaults() {
        return [
            'secret'              => '',
            'cpt'                 => 'jackpot',
            // ACF field names. Per the brief the two live ACF fields are
            // "amount" and "shared_profit_amount".
            'field_amount'        => 'amount',
            'field_shared'        => 'shared_profit_amount',
            // 1 = use the number as-is, 100 = divide by 100 (cents -> units).
            'divisor'             => 1,
            'max_keep'            => 20,
            // 'jp_name' or 'jp_id'.
            'image_matching_mode' => 'jp_name',
        ];
    }

    /**
     * Return the full, normalized settings array.
     *
     * @return array<string,mixed>
     */
    public static function all() {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return wp_parse_args($stored, self::defaults());
    }

    /**
     * Read a single setting value.
     *
     * The shared secret can also be defined in wp-config.php as JACKPOT_SECRET,
     * which always takes priority over the value saved on the settings page.
     *
     * @param string $key Setting key.
     * @return mixed|null
     */
    public static function get($key) {
        if ($key === 'secret' && defined('JACKPOT_SECRET') && JACKPOT_SECRET !== '') {
            return JACKPOT_SECRET;
        }

        $settings = self::all();

        return array_key_exists($key, $settings) ? $settings[$key] : null;
    }

    /**
     * True when the shared secret is locked via wp-config.php.
     *
     * @return bool
     */
    public static function secret_is_constant() {
        return defined('JACKPOT_SECRET') && JACKPOT_SECRET !== '';
    }

    /**
     * Sanitize raw settings input from the admin form.
     *
     * @param array<string,mixed> $input Raw form input.
     * @return array<string,mixed>
     */
    public static function sanitize($input) {
        $defaults = self::defaults();
        $input    = is_array($input) ? $input : [];
        $out      = [];

        $out['secret']       = isset($input['secret']) ? trim(sanitize_text_field($input['secret'])) : '';
        $out['cpt']          = !empty($input['cpt']) ? sanitize_key($input['cpt']) : $defaults['cpt'];
        $out['field_amount'] = !empty($input['field_amount']) ? sanitize_key($input['field_amount']) : $defaults['field_amount'];
        $out['field_shared'] = !empty($input['field_shared']) ? sanitize_key($input['field_shared']) : $defaults['field_shared'];
        $out['divisor']      = (isset($input['divisor']) && (int) $input['divisor'] === 100) ? 100 : 1;
        $out['max_keep']     = isset($input['max_keep']) ? max(1, (int) $input['max_keep']) : $defaults['max_keep'];
        $out['image_matching_mode'] = (isset($input['image_matching_mode']) && $input['image_matching_mode'] === 'jp_id')
            ? 'jp_id'
            : 'jp_name';

        return $out;
    }

    /**
     * Public REST endpoint URL (where the Worker POSTs).
     *
     * @return string
     */
    public static function endpoint_url() {
        return rest_url('jackpot/v1/update');
    }

    /**
     * Public health-check URL.
     *
     * @return string
     */
    public static function ping_url() {
        return rest_url('jackpot/v1/ping');
    }
}
