<?php
/**
 * Result value object.
 *
 * Lightweight container returned by the creator/updater services so the
 * processor can build a REST response and log metrics from a single source.
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable-ish result of processing a message.
 */
class Jackpot_Sync_Result {

    /** @var int HTTP status code. */
    public $status;

    /** @var array<string,mixed> Response body. */
    public $body;

    /** @var array<string,mixed> Structured context for logging/metrics. */
    public $context;

    /**
     * @param int                 $status  HTTP status code.
     * @param array<string,mixed> $body    Response body.
     * @param array<string,mixed> $context Logging context.
     */
    public function __construct($status, array $body, array $context = []) {
        $this->status  = (int) $status;
        $this->body    = $body;
        $this->context = $context;
    }

    /**
     * True when the status is a 2xx success.
     *
     * @return bool
     */
    public function is_success() {
        return $this->status >= 200 && $this->status < 300;
    }
}
