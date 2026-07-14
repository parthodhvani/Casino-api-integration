<?php
/**
 * REST controller (thin).
 *
 * Registers the public endpoints and delegates all work to the processor.
 * Responsible only for HTTP concerns: routing, signature verification and
 * translating a Jackpot_Sync_Result into a WP_REST_Response.
 *
 *   POST /wp-json/jackpot/v1/update   (signed)
 *   GET  /wp-json/jackpot/v1/ping     (open health check)
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * HTTP entry point for the Worker.
 */
class Jackpot_Sync_Rest_Controller {

    /** @var Jackpot_Sync_Processor */
    private $processor;

    /**
     * @param Jackpot_Sync_Processor $processor Message processor.
     */
    public function __construct(Jackpot_Sync_Processor $processor) {
        $this->processor = $processor;
    }

    /**
     * Hook route registration.
     *
     * @return void
     */
    public function register() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST routes.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route('jackpot/v1', '/update', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_update'],
            'permission_callback' => [$this, 'verify_signature'],
        ]);

        register_rest_route('jackpot/v1', '/ping', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_ping'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Health check endpoint.
     *
     * @return WP_REST_Response
     */
    public function handle_ping() {
        return new WP_REST_Response([
            'ok'      => true,
            'plugin'  => 'jackpot-sync',
            'version' => defined('JACKPOT_SYNC_VERSION') ? JACKPOT_SYNC_VERSION : 'unknown',
            'secret'  => !empty(Jackpot_Sync_Settings::get('secret')),
            'acf'     => function_exists('update_field'),
            'cpt'     => post_type_exists(Jackpot_Sync_Settings::get('cpt')),
            'time'    => current_time('mysql'),
        ], 200);
    }

    /**
     * Verify the HMAC-SHA256 signature using a constant-time comparison.
     *
     * @param WP_REST_Request $request Incoming request.
     * @return bool
     */
    public function verify_signature(WP_REST_Request $request) {
        $secret = Jackpot_Sync_Settings::get('secret');

        if (empty($secret)) {
            Jackpot_Sync_Logger::log('Rejected: shared secret is not configured yet.');
            return false;
        }

        $provided = $request->get_header('x-signature');
        if (!$provided) {
            Jackpot_Sync_Logger::log('Rejected: missing X-Signature header.');
            return false;
        }

        // Sign the exact raw body bytes — never a re-encoded version.
        $expected = hash_hmac('sha256', $request->get_body(), $secret);

        if (!hash_equals($expected, $provided)) {
            Jackpot_Sync_Logger::log('Rejected: signature mismatch (wrong secret?).');
            return false;
        }

        return true;
    }

    /**
     * Handle a signed update request.
     *
     * @param WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    public function handle_update(WP_REST_Request $request) {
        $data   = $request->get_json_params();
        $result = $this->processor->process($data, 'rest');

        return new WP_REST_Response($result->body, $result->status);
    }
}
