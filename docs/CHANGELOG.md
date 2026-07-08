# Changelog

All notable changes to this project are documented here. The project follows
semantic-ish versioning aligned with the plugin version.

## [3.0.0] — 2026-07-08

Full production hardening across all three components.

### WordPress plugin

**Added**
- Modular service architecture: `Settings`, `Logger`, `Message_Parser`,
  `Prize_Formatter`, `Image_Resolver`, `Repository`, `Cache`, `Result`,
  `Creator`, `Updater`, `Processor`, `Rest_Controller`, `Retention`, `Admin`,
  `Plugin` (container).
- Runtime metrics dashboard (created/updated/skipped/images/errors, last
  request info, execution time, worker status) on the settings page.
- Health checks (secret, ACF, CPT, retention cron) and a richer `/ping`.
- **Tools → Jackpot Tester** page that runs a raw message through the same
  processor as REST.
- Activity log with structured context + “Clear log”.
- `ImageResolver` that finds featured images in the local Media Library only
  (by `jpName` title or `<jpId>.ext` filename), configurable, non-blocking.
- Configurable **image matching mode** setting.
- PHP functional test suite (`wordpress-plugin/tests/`, 48 checks).
- Backward-compatibility procedural shim (`includes/helpers.php`).

**Changed**
- Matching is now by `jpId` only (never title/amount/image).
- ACF `amount` field default renamed from `jackpot_amount` to `amount`.
- Cache purge expanded to object cache, post cache, transients, and LiteSpeed
  (including ESI), plus a `jackpot_sync_after_purge` hook.
- Signature verification documented as constant-time (`hash_equals`) over the
  exact raw body.
- REST controller is now thin; all logic lives in services.

**Removed**
- All remote image handling (`media_sideload_image`, `imageUrl` dependency).

### Cloudflare Worker

**Added**
- Modular files (`hmac`, `sites`, `validator`, `forwarder`, `index`).
- Constant-time listener-secret check.
- Payload validation at the edge; batch (array) payload support.
- Per-message per-site HMAC signing, retry (5xx/network) + timeout.
- Structured JSON logs and aggregated metrics + per-site results.
- `node:test` suite (11 tests) incl. a known HMAC vector.
- Tuning vars `FORWARD_RETRIES`, `FORWARD_TIMEOUT_MS`.

**Changed**
- Upgraded `wrangler` devDependency to v4 → `npm audit` reports 0 vulnerabilities.

### MQTT listener

**Added**
- Structured JSON logger, heartbeat, forward retry + timeout, multi-topic
  support, optional healthcheck ping.
- Robust graceful shutdown for `SIGINT` and `SIGTERM`.
- `node:test` suites (parser + forwarder, 10 tests).

**Changed**
- Config validation with defaults and new tuning env vars.
- Parser trims fields and rejects missing `jpId`/`casId`.

### Tools & docs

- `simulate-message.js` gains a `--direct` mode (signs + posts straight to WP).
- New documentation set: `README`, `docs/SETUP`, `INSTALL`, `TESTING`,
  `DEPLOYMENT`, `ARCHITECTURE`, `SECURITY`, `TROUBLESHOOTING`, `CHANGELOG`.

## [2.0.0]

- Admin-configured release: signed REST endpoint, ACF writes, cache purge,
  retention cron, activity log.
