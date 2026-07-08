# QA Report — Jackpot Sync v3.0.0

Date: 2026-07-08
Environment: Linux, PHP 8.3.6 (CLI), Node.js v22.14.0, npm 10.9.7, Wrangler 4.108.0

## Summary

| Area | Result |
|------|--------|
| PHP syntax lint (all plugin files) | PASS (0 failures) |
| PHP functional suite (`tests/run-tests.php`) | PASS (48/48) |
| Plugin activation + admin render harness | PASS (no PHP notices at `E_ALL`) |
| Packaged ZIP extract + lint + activate | PASS |
| Cloudflare Worker tests (`node:test`) | PASS (11/11) |
| Worker build (`wrangler deploy --dry-run`) | PASS |
| Worker `npm audit` | 0 vulnerabilities |
| MQTT listener tests (`node:test`) | PASS (10/10) |
| Listener boot / fail-fast / graceful shutdown | PASS (SIGTERM → clean exit 0) |
| Listener `npm audit` | 0 vulnerabilities |

Total automated checks: **69** (48 PHP + 11 Worker + 10 Listener), all passing.

## What was verified (evidence)

### WordPress plugin

- Every PHP file passes `php -l`.
- Functional pipeline (against in-memory WP stubs):
  - JPCONFIG creates a published post titled from `jpName`, stores hidden +
    legacy meta, resolves image or logs a warning without failing.
  - Duplicate JPCONFIG is skipped (no duplicate post).
  - JPUPDATE updates `amount` + `shared_profit_amount` with cents conversion.
  - JPUPDATE before JPCONFIG returns 202 (skip).
  - Retention keeps newest N and deletes the rest.
  - Image resolver matches by `jpName` title and by `<jpId>.ext` filename.
  - HMAC parity + constant-time compare.
- Activation harness loads the plugin as WordPress would, fires `plugins_loaded`
  + activation + deactivation hooks, renders the settings and tester pages, and
  registers REST routes + `/ping` (200) with `error_reporting=E_ALL` and no
  warnings/notices.
- The built `jackpot-sync-v3.0.0.zip` was extracted to a clean directory, linted,
  and activated successfully (single top-level `jackpot-sync/` folder, 23 files).

### Cloudflare Worker

- 11 unit tests: HMAC (incl. known vector), constant-time compare, `WP_SITES`
  parsing, message validation, batch/single body parsing, forwarder retry
  (5xx retried, 4xx not, no-secret error).
- `wrangler deploy --dry-run` builds successfully (7.51 KiB).
- `npm audit`: 0 vulnerabilities (Wrangler pinned to v4).

### MQTT listener

- 10 unit tests: parser (incl. whitespace + missing-field rejection) and
  forwarder retry/timeout.
- Boot: fails fast with a clear message on missing env vars.
- Runtime: connects, logs structured JSON, auto-reconnects on error.
- Shutdown: `SIGTERM` triggers the shutdown handler and exits cleanly (code 0),
  verified via a child-process harness.
- `npm audit`: 0 vulnerabilities.

## Requires external access (not verifiable in this environment)

These need live credentials/services and are expected to be validated in the
client environment:

1. **Live WordPress site** — real ACF `update_field` writes and Elementor
   carousel rendering (the plugin falls back to `update_post_meta` when ACF is
   absent; ACF calls are exercised only on a site with ACF active).
2. **Cloudflare account** — actual `wrangler deploy` and secret storage
   (`JACKPOT_SECRET`, `LISTENER_SECRET`). Build + config are verified via
   dry-run.
3. **HiveMQ broker** — real MQTT connection + live DRGT messages. Connection
   wiring, parsing, reconnect and shutdown are verified against an unreachable
   broker + unit tests.
4. **Host WAF** — confirm the `/wp-json/jackpot/v1/update` route and the
   `X-Signature` header are not blocked/stripped (see TROUBLESHOOTING.md).

## How to reproduce

```bash
# PHP
php wordpress-plugin/tests/run-tests.php
php wordpress-plugin/tests/activation-harness.php wordpress-plugin/jackpot-sync

# Worker
cd cloudflare-worker && npm install && npm test && npx wrangler deploy --dry-run

# Listener
cd mqtt-listener && npm install && npm test
```
