# Changelog

All notable changes to this project are documented here. The project follows
semantic-ish versioning aligned with the plugin version.

## [3.1.1] — 2026-07-14

### Cloudflare Worker

**Changed**
- Merged all Worker modules into a single pasteable [`cloudflare-worker/worker.js`](../cloudflare-worker/worker.js)
  for Cloudflare Dashboard → Edit Code (no Wrangler required).
- `wrangler.toml` `main` now points at `worker.js`.
- `src/*.js` are thin re-exports of `worker.js` for backward compatibility.

## [3.1.0] — 2026-07-14

MQTT Start / Stop control across the full stack. Architecture unchanged:
DRGT → Node listener → Cloudflare Worker → WordPress → CPT/ACF → Elementor.

### MQTT listener

**Added**
- Controllable MQTT lifecycle: `startMQTT()`, `stopMQTT()`, `isRunning()`,
  `getStatus()` (no connect on boot by default).
- HTTP control API: `POST /start`, `POST /stop`, `GET /status`, `GET /health`.
- Built-in daily schedule (06:00 start / 08:00 stop, `SCHEDULE_*` env vars).
- Constant-time `x-listener-secret` auth on control endpoints.
- Duplicate-connection / duplicate-subscription guards; safe reconnect while running.
- Unit tests for manager, control server, scheduler helpers.

**Changed**
- Process stays alive on MQTT stop; only the broker connection is closed.
- Optional `MQTT_AUTO_START=true` restores legacy “connect on boot” behavior.

### Cloudflare Worker

**Added**
- Control proxies: `POST /start`, `POST /stop`, `GET /status`.
- Auth: `x-listener-secret` **or** HMAC `X-Signature` with `JACKPOT_SECRET`.
- `LISTENER_CONTROL_URL` / `CONTROL_TIMEOUT_MS` configuration.
- Forwards to Node and surfaces timeout / unavailable errors (502/504).

### WordPress plugin

**Added**
- **Settings → Jackpot Sync → MQTT Listener** panel (status, last sync/message/config).
- AJAX buttons: Start MQTT, Stop MQTT, Refresh Status (nonce-protected, no reload).
- `Jackpot_Sync_Mqtt_Control` client (HMAC-signed Worker calls + error notices).
- Setting: Cloudflare Worker URL.
- Cached MQTT status option + logger metrics (`mqtt_state`, `mqtt_last_*`).

### Docs

- New [`docs/SCHEDULING.md`](SCHEDULING.md) — Linux Cron, PM2, Systemd examples.
- Architecture / testing / deployment updates for control flow.

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
