<?php
/**
 * Plugin container.
 *
 * Wires the service objects together once and exposes the processor used by
 * both the REST controller and the admin tester. Acts as a tiny service
 * locator so the rest of the code stays dependency-injected and testable.
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central service container + bootstrapper.
 */
class Jackpot_Sync_Plugin {

    /** @var Jackpot_Sync_Plugin|null */
    private static $instance = null;

    /** @var Jackpot_Sync_Processor */
    private $processor;

    /** @var Jackpot_Sync_Cache */
    private $cache;

    /** @var Jackpot_Sync_Repository */
    private $repository;

    /**
     * Build the object graph.
     */
    private function __construct() {
        $this->repository = new Jackpot_Sync_Repository();
        $this->cache      = new Jackpot_Sync_Cache();

        $image_resolver = new Jackpot_Sync_Image_Resolver();
        $formatter      = new Jackpot_Sync_Prize_Formatter();

        $creator = new Jackpot_Sync_Creator($this->repository, $image_resolver);
        $updater = new Jackpot_Sync_Updater($this->repository, $formatter, $this->cache);

        $this->processor = new Jackpot_Sync_Processor($creator, $updater);
    }

    /**
     * Singleton accessor.
     *
     * @return Jackpot_Sync_Plugin
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @return Jackpot_Sync_Processor
     */
    public function processor() {
        return $this->processor;
    }

    /**
     * @return Jackpot_Sync_Cache
     */
    public function cache() {
        return $this->cache;
    }

    /**
     * @return Jackpot_Sync_Repository
     */
    public function repository() {
        return $this->repository;
    }

    /**
     * Register runtime hooks (REST, retention, admin).
     *
     * @return void
     */
    public function register() {
        ( new Jackpot_Sync_Rest_Controller($this->processor) )->register();
        ( new Jackpot_Sync_Retention($this->cache) )->register();

        if (is_admin()) {
            ( new Jackpot_Sync_Admin($this->processor) )->register();
        }
    }
}
