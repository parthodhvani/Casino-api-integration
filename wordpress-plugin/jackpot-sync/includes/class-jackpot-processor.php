<?php
/**
 * Processor / orchestrator.
 *
 * The single entry point that both the REST controller and the admin tester
 * call. It validates the payload, routes to the creator or updater, records
 * metrics + logs, and returns a Jackpot_Sync_Result.
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coordinates message processing.
 */
class Jackpot_Sync_Processor {

    /** @var Jackpot_Sync_Creator */
    private $creator;

    /** @var Jackpot_Sync_Updater */
    private $updater;

    /**
     * @param Jackpot_Sync_Creator $creator Creator service.
     * @param Jackpot_Sync_Updater $updater Updater service.
     */
    public function __construct(Jackpot_Sync_Creator $creator, Jackpot_Sync_Updater $updater) {
        $this->creator = $creator;
        $this->updater = $updater;
    }

    /**
     * Validate and process a raw (decoded) payload.
     *
     * @param mixed  $data   Decoded payload.
     * @param string $source Origin label ("rest" or "admin_tester").
     * @return Jackpot_Sync_Result
     */
    public function process($data, $source = 'rest') {
        $started  = microtime(true);
        $validated = Jackpot_Sync_Message_Parser::validate_payload($data);

        if (is_wp_error($validated)) {
            $status = (int) ($validated->get_error_data()['status'] ?? 400);
            $result = new Jackpot_Sync_Result(
                $status,
                ['ok' => false, 'error' => $validated->get_error_message()],
                ['action' => 'error', 'error' => $validated->get_error_message()]
            );
            return $this->finalize($result, $started, $source, is_array($data) ? $data : []);
        }

        if ($validated['type'] === 'JPCONFIG') {
            $result = $this->creator->handle($validated);
        } else {
            $result = $this->updater->handle($validated);
        }

        return $this->finalize($result, $started, $source, $validated);
    }

    /**
     * Record metrics + logs and return the result.
     *
     * @param Jackpot_Sync_Result $result   Result to finalize.
     * @param float               $started  Start time (microtime).
     * @param string              $source   Origin label.
     * @param array<string,mixed> $payload  Payload (validated or raw).
     * @return Jackpot_Sync_Result
     */
    private function finalize(Jackpot_Sync_Result $result, $started, $source, array $payload) {
        $elapsed_ms = (int) round((microtime(true) - $started) * 1000);
        $type       = isset($payload['type']) ? (string) $payload['type'] : '';
        $jp_id      = isset($payload['jpId']) ? (string) $payload['jpId'] : '';
        $cas_id     = isset($payload['casId']) ? (string) $payload['casId'] : '';
        $error      = isset($result->context['error']) ? (string) $result->context['error'] : '';

        $stats = [
            'last_update'       => current_time('mysql'),
            'last_worker_time'  => current_time('mysql'),
            'last_jpid'         => $jp_id,
            'last_casino_id'    => $cas_id,
            'last_message_type' => $type,
            'last_execution_ms' => $elapsed_ms,
            'last_http_code'    => $result->status,
            'worker_status'     => $result->is_success() ? 'Healthy (last request succeeded)' : 'Error on last request',
        ];

        if ($error !== '') {
            $stats['last_error'] = $error;
            Jackpot_Sync_Logger::increment('errors');
        } elseif ($result->is_success()) {
            $stats['last_error'] = '';
        }

        Jackpot_Sync_Logger::update_stats($stats);

        Jackpot_Sync_Logger::log('Processed ' . ($type ?: 'message'), array_merge($result->context, [
            'source'        => $source,
            'message_type'  => $type,
            'jp_id'         => $jp_id,
            'cas_id'        => $cas_id,
            'http_response' => $result->status,
            'execution_ms'  => $elapsed_ms,
            'error'         => $error,
        ]));

        return $result;
    }
}
