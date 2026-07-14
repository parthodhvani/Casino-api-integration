<?php
/**
 * MQTT Listener control client.
 *
 * Talks to the Cloudflare Worker control endpoints (/start, /stop, /status),
 * which proxy to the Node MQTT listener. Authenticated with HMAC-SHA256 using
 * the same shared secret as the REST update endpoint.
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Proxies admin MQTT controls through the Worker.
 */
class Jackpot_Sync_Mqtt_Control {

    const STATUS_OPTION = 'jackpot_sync_mqtt_status';
    const TIMEOUT       = 12;

    /**
     * Default cached status shape shown in the admin UI.
     *
     * @return array<string,mixed>
     */
    public static function defaults() {
        return [
            'status'            => 'Unknown',
            'running'           => false,
            'connectionState'   => 'unknown',
            'lastSyncTime'      => '',
            'lastMessageAt'     => '',
            'lastConfigUpdate'  => '',
            'lastError'         => '',
            'fetched_at'        => '',
            'source'            => 'none',
        ];
    }

    /**
     * Return the last cached MQTT status (may be stale).
     *
     * @return array<string,mixed>
     */
    public static function get_cached_status() {
        $stored = get_option(self::STATUS_OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return wp_parse_args($stored, self::defaults());
    }

    /**
     * Persist a status snapshot for the admin page.
     *
     * @param array<string,mixed> $status Status payload.
     * @return void
     */
    public static function cache_status(array $status) {
        $merged = wp_parse_args($status, self::defaults());
        $merged['fetched_at'] = current_time('mysql');
        update_option(self::STATUS_OPTION, $merged, false);
    }

    /**
     * Start MQTT via the Worker.
     *
     * @return array<string,mixed> Normalized result for AJAX.
     */
    public static function start() {
        $result = self::request('start', 'POST');
        if (!empty($result['ok'])) {
            $live = self::request('status', 'GET');
            if (!empty($live['ok']) && !empty($live['status_payload'])) {
                $result['status_payload'] = $live['status_payload'];
                $result['data'] = isset($live['data']) ? $live['data'] : $result['data'];
            }
        }
        return $result;
    }

    /**
     * Stop MQTT via the Worker.
     *
     * @return array<string,mixed>
     */
    public static function stop() {
        $result = self::request('stop', 'POST');
        if (!empty($result['ok'])) {
            $live = self::request('status', 'GET');
            if (!empty($live['ok']) && !empty($live['status_payload'])) {
                $result['status_payload'] = $live['status_payload'];
                $result['data'] = isset($live['data']) ? $live['data'] : $result['data'];
            }
        }
        return $result;
    }

    /**
     * Fetch live MQTT status via the Worker and cache it.
     *
     * @return array<string,mixed>
     */
    public static function status() {
        return self::request('status', 'GET');
    }

    /**
     * Perform a signed control request against the Worker.
     *
     * @param string $action start|stop|status
     * @param string $method GET|POST
     * @return array<string,mixed>
     */
    private static function request($action, $method) {
        $worker_url = Jackpot_Sync_Settings::get('worker_url');
        $secret     = Jackpot_Sync_Settings::get('secret');

        if (empty($worker_url)) {
            return self::fail(
                'worker_not_configured',
                __('Worker URL is not configured. Add it under Settings → Jackpot Sync.', 'jackpot-sync')
            );
        }

        if (empty($secret)) {
            return self::fail(
                'secret_not_configured',
                __('Shared secret is not configured. MQTT control requires the same secret used for jackpot updates.', 'jackpot-sync')
            );
        }

        $url  = self::control_url($worker_url, $action);
        $body = ($method === 'POST') ? '{}' : '';
        $sig  = hash_hmac('sha256', $body, $secret);

        $args = [
            'method'  => $method,
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Signature'  => $sig,
                'Accept'       => 'application/json',
            ],
        ];

        if ($method === 'POST') {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $code = $response->get_error_code();
            $msg  = $response->get_error_message();

            if (strpos($msg, 'timed out') !== false || $code === 'http_request_failed') {
                return self::fail(
                    'timeout',
                    sprintf(
                        /* translators: %s: error detail */
                        __('Request to Worker timed out or failed: %s', 'jackpot-sync'),
                        $msg
                    )
                );
            }

            return self::fail(
                'worker_unavailable',
                sprintf(
                    /* translators: %s: error detail */
                    __('Worker unavailable: %s', 'jackpot-sync'),
                    $msg
                )
            );
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $raw       = wp_remote_retrieve_body($response);
        $data      = json_decode($raw, true);

        if ($http_code === 401) {
            return self::fail(
                'auth_failure',
                __('Authentication failed. Check that the shared secret matches JACKPOT_SECRET on the Worker.', 'jackpot-sync'),
                $http_code
            );
        }

        if ($http_code === 502 || $http_code === 504) {
            $detail = is_array($data) && !empty($data['error']) ? $data['error'] : __('Node listener unavailable.', 'jackpot-sync');
            $hint   = is_array($data) && !empty($data['hint']) ? ' ' . $data['hint'] : '';
            $body   = is_array($data) && !empty($data['body']) ? ' Response: ' . $data['body'] : '';
            return self::fail(
                'node_unavailable',
                sprintf(
                    /* translators: %s: error detail */
                    __('Node listener error: %s', 'jackpot-sync'),
                    $detail
                ) . $hint . $body,
                $http_code,
                is_array($data) ? $data : []
            );
        }

        if ($http_code === 500 && is_array($data) && !empty($data['error'])) {
            $hint = !empty($data['hint']) ? ' ' . $data['hint'] : '';
            return self::fail('worker_error', (string) $data['error'] . $hint, $http_code, $data);
        }

        if (!is_array($data)) {
            return self::fail(
                'invalid_response',
                __('Invalid JSON response from Worker.', 'jackpot-sync'),
                $http_code
            );
        }

        // Normalize + cache status-like payloads.
        $normalized = self::normalize_payload($action, $data, $http_code);
        if (!empty($normalized['status_payload'])) {
            self::cache_status($normalized['status_payload']);
        }

        Jackpot_Sync_Logger::log(
            'MQTT control: ' . $action,
            [
                'ok'        => $normalized['ok'],
                'http'      => $http_code,
                'status'    => $normalized['status_payload']['status'] ?? ($data['status'] ?? ''),
            ]
        );

        // Mirror useful timestamps into runtime stats when present.
        $stats_update = [];
        if (!empty($normalized['status_payload']['lastSyncTime'])) {
            $stats_update['mqtt_last_sync'] = $normalized['status_payload']['lastSyncTime'];
        }
        if (!empty($normalized['status_payload']['lastMessageAt'])) {
            $stats_update['mqtt_last_message'] = $normalized['status_payload']['lastMessageAt'];
        }
        if (!empty($normalized['status_payload']['lastConfigUpdate'])) {
            $stats_update['mqtt_last_config'] = $normalized['status_payload']['lastConfigUpdate'];
        }
        if (isset($normalized['status_payload']['status'])) {
            $stats_update['mqtt_state'] = $normalized['status_payload']['status'];
        }
        if (!empty($stats_update)) {
            Jackpot_Sync_Logger::update_stats($stats_update);
        }

        return $normalized;
    }

    /**
     * Build Worker control URL.
     *
     * @param string $base   Worker base URL.
     * @param string $action Control action.
     * @return string
     */
    private static function control_url($base, $action) {
        $base = untrailingslashit(trim($base));
        return $base . '/' . ltrim($action, '/');
    }

    /**
     * Normalize Worker/Node responses into a consistent AJAX payload.
     *
     * @param string              $action    Control action.
     * @param array<string,mixed> $data      Decoded JSON.
     * @param int                 $http_code HTTP status.
     * @return array<string,mixed>
     */
    private static function normalize_payload($action, array $data, $http_code) {
        $ok = $http_code >= 200 && $http_code < 300;

        $status_payload = null;
        if ($action === 'status' || isset($data['running']) || isset($data['connectionState'])) {
            $status_payload = [
                'status'           => isset($data['status']) ? (string) $data['status'] : 'Unknown',
                'running'          => !empty($data['running']),
                'connectionState'  => isset($data['connectionState']) ? (string) $data['connectionState'] : 'unknown',
                'lastSyncTime'     => isset($data['lastSyncTime']) ? (string) $data['lastSyncTime'] : '',
                'lastMessageAt'    => isset($data['lastMessageAt']) ? (string) $data['lastMessageAt'] : '',
                'lastConfigUpdate' => isset($data['lastConfigUpdate']) ? (string) $data['lastConfigUpdate'] : '',
                'lastError'        => isset($data['lastError']) ? (string) $data['lastError'] : '',
                'source'           => 'live',
            ];
        } elseif ($action === 'start' || $action === 'stop') {
            // After start/stop, synthesize a minimal status for the UI.
            $label = 'Unknown';
            $running = null;
            if (!empty($data['status'])) {
                if ($data['status'] === 'started' || $data['status'] === 'already_running') {
                    $label = 'Running';
                    $running = true;
                } elseif ($data['status'] === 'stopped' || $data['status'] === 'already_stopped') {
                    $label = 'Stopped';
                    $running = false;
                }
            }
            if ($running !== null) {
                $cached = self::get_cached_status();
                $status_payload = array_merge($cached, [
                    'status'  => $label,
                    'running' => $running,
                    'source'  => 'action',
                ]);
            }
        }

        $message = '';
        if (isset($data['status'])) {
            $message = (string) $data['status'];
        } elseif (isset($data['error'])) {
            $message = (string) $data['error'];
            $ok = false;
        }

        return [
            'ok'             => $ok,
            'action'         => $action,
            'http_code'      => $http_code,
            'message'        => $message,
            'data'           => $data,
            'status_payload' => $status_payload,
        ];
    }

    /**
     * Build a failure result.
     *
     * @param string              $code      Machine-readable code.
     * @param string              $message   Human-readable message.
     * @param int                 $http_code Optional HTTP code.
     * @param array<string,mixed> $data      Optional payload.
     * @return array<string,mixed>
     */
    private static function fail($code, $message, $http_code = 0, array $data = []) {
        Jackpot_Sync_Logger::log('MQTT control error: ' . $code, [
            'message' => $message,
            'http'    => $http_code,
        ]);
        Jackpot_Sync_Logger::increment('errors');
        Jackpot_Sync_Logger::update_stats([
            'last_error' => $message,
            'mqtt_state' => 'Error',
        ]);

        return [
            'ok'             => false,
            'error_code'     => $code,
            'message'        => $message,
            'http_code'      => $http_code,
            'data'           => $data,
            'status_payload' => null,
        ];
    }

    /**
     * Format an ISO timestamp for admin display.
     *
     * @param string $iso ISO-8601 timestamp.
     * @return string
     */
    public static function format_time($iso) {
        if (empty($iso)) {
            return '—';
        }

        $ts = strtotime($iso);
        if (!$ts) {
            return (string) $iso;
        }

        // Prefer wp_date when available (WP 5.3+); fall back for stubbed tests.
        if (function_exists('wp_date')) {
            return wp_date('j M Y g:i A', $ts);
        }

        return date('j M Y g:i A', $ts);
    }
}
