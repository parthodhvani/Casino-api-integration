# Architecture

## Overview

The system turns DRGT's MQTT jackpot feed into automatic updates of the Jackpot
CPT + ACF fields on one or more WordPress sites, which the existing Elementor
carousel renders. WordPress never connects to MQTT directly — a listener and a
Cloudflare Worker sit in between.

```
[DRGT] --MQTT--> [Listener] --HTTPS--> [Worker] --HTTPS(signed)--> [WordPress ×N]
                                                                       │
                                                        Jackpot CPT → ACF → Elementor
```

### MQTT control plane (v3.1)

```
[WP Admin buttons / Cron / Schedule]
              │  AJAX or HTTPS
              ▼
     [Cloudflare Worker]   POST /start  POST /stop  GET /status
              │  (auth: LISTENER_SECRET or HMAC JACKPOT_SECRET)
              ▼
     [Node Listener HTTP]  POST /start  POST /stop  GET /status
              │
              ▼
     startMQTT() / stopMQTT()   ← single MQTT client, process stays alive
```

## Why a listener AND a Worker?

A Cloudflare Worker is event-driven and cannot hold a permanent MQTT connection
open. The small Node listener keeps the MQTT session alive; the Worker does the
authentication, validation, signing and fan-out to the sites. This split also
means the signing secret never lives on the always-on listener host.

Control requests (start/stop/status) reverse the usual direction: WordPress or
cron talks to the Worker, which proxies to the listener's HTTP control API.

## Components

### 1. MQTT Listener (`mqtt-listener/`)

| File | Responsibility |
|------|----------------|
| `src/index.js` | Boots control server + optional scheduler; does not connect MQTT on start. |
| `src/mqtt-manager.js` | `startMQTT` / `stopMQTT` / `isRunning` / `getStatus` — one client only. |
| `src/control-server.js` | HTTP `POST /start`, `POST /stop`, `GET /status`, `GET /health`. |
| `src/scheduler.js` | Daily 06:00 connect / 08:00 disconnect (configurable). |
| `src/security.js` | Constant-time secret compare. |
| `src/config.js` | Loads + validates env vars (multi-topic, control, schedule, tuning). |
| `src/parser.js` | Converts semicolon messages to normalized JSON (never throws). |
| `src/forwarder.js` | POSTs to the Worker with timeout + exponential-backoff retry. |
| `src/logger.js` | Structured single-line JSON logging. |

Behavior: controllable MQTT, auto-reconnect while running, per-message forward
with retry, periodic heartbeat log, optional dead-man's-switch ping, clean
`SIGINT`/`SIGTERM` shutdown (stops MQTT then exits the process).

### 2. Cloudflare Worker (`cloudflare-worker/`)

| File | Responsibility |
|------|----------------|
| `worker.js` | **Single-file Worker** (paste into Cloudflare Dashboard Edit Code). Contains entry + HMAC + sites + validator + forwarder + control. |
| `src/*.js` | Thin re-exports of `worker.js` (backward compatible imports). |
| `wrangler.toml` | `main = "worker.js"` plus `WP_SITES` / control URL vars. |

Behavior: constant-time listener-secret check (messages + control), optional
HMAC for WordPress control calls, payload validation at the edge, per-message
per-site HMAC signature, batching, retry/timeout, structured logs.

### 3. WordPress plugin (`wordpress-plugin/jackpot-sync/`)

Modular service classes under `includes/` (each single-responsibility):

| Class | Responsibility |
|-------|----------------|
| `Jackpot_Sync_Settings` | Typed settings access + defaults + sanitize. |
| `Jackpot_Sync_Logger` | Activity log + runtime metrics store. |
| `Jackpot_Sync_Message_Parser` | Parse raw lines + validate JSON payloads. |
| `Jackpot_Sync_Prize_Formatter` | Value formatting (divisor: units/cents). |
| `Jackpot_Sync_Image_Resolver` | Resolve featured image from local Media Library. |
| `Jackpot_Sync_Repository` | Find/create posts, read/write meta (jpId lookup). |
| `Jackpot_Sync_Cache` | Purge object/post/transient/LiteSpeed(+ESI) caches. |
| `Jackpot_Sync_Result` | Value object (status/body/context). |
| `Jackpot_Sync_Creator` | JPCONFIG handling. |
| `Jackpot_Sync_Updater` | JPUPDATE handling. |
| `Jackpot_Sync_Processor` | Orchestrator used by REST + admin tester. |
| `Jackpot_Sync_Rest_Controller` | Thin HTTP layer (routes, signature, response). |
| `Jackpot_Sync_Retention` | Hourly cron pruning to newest N. |
| `Jackpot_Sync_Mqtt_Control` | HMAC-signed Worker calls for MQTT start/stop/status. |
| `Jackpot_Sync_Admin` | Settings page, MQTT panel, AJAX, Tools → Jackpot Tester. |
| `Jackpot_Sync_Plugin` | Service container + bootstrapper. |

`includes/helpers.php` keeps the old procedural API (`jackpot_get`,
`jackpot_log`, etc.) as thin wrappers for backward compatibility.

## Data flow

### JPCONFIG (create)

```
Worker POST → verify_signature → Processor.process
  → validate_payload (type/jpId/casId/jpName)
  → Creator.handle
      → Repository.find_by_jpid(jpId)
          exists?  → skip (200, created:false)
          missing? → create publish post (title = jpName)
                     → save hidden + legacy meta (jpId, casId, level, jpType, prizeName, jpName)
                     → ImageResolver.resolve (local Media Library; jpName or jpId)
                         found?   → set_post_thumbnail
                         missing? → log warning, continue (never fail)
                     → 201 created
  → metrics + structured log
```

### JPUPDATE (update)

```
Worker POST → verify_signature → Processor.process
  → validate_payload (type/jpId/casId + numeric jpValue/jpShared)
  → Updater.handle
      → Repository.find_by_jpid(jpId)
          missing? → skip (202, waiting for JPCONFIG)
          found?   → PrizeFormatter.format(jpValue), format(jpShared)
                     → update ACF `amount` + `shared_profit_amount` (or post meta fallback)
                     → touch modified date
                     → Cache.purge
                     → 200 ok
  → metrics + structured log
```

### MQTT control (admin / cron)

```
Admin button → admin-ajax.php (nonce + manage_options)
  → Jackpot_Sync_Mqtt_Control (HMAC X-Signature with shared secret)
  → Worker /start|/stop|/status
  → Node control API (x-listener-secret)
  → startMQTT() / stopMQTT() / getStatus()
  → JSON back to admin UI (no page reload)
```

## Identity + storage

- The **unique key is `jpId`** (never title, amount, or image).
- Hidden meta: `_jackpot_jpId`, `_jackpot_casId`, `_jackpot_level`,
  `_jackpot_jpType`, `_jackpot_prizeName`, `_jackpot_jpName`.
- Plain + legacy meta also written for template/back-compat:
  `jpId`, `casId`, `level`, `jpType`, `prizeName`, `jpName`, `jp_id`, `cas_id`.
- ACF fields written on JPUPDATE: `amount`, `shared_profit_amount`
  (configurable; ACF `update_field` when available, else `update_post_meta`).

## Extensibility

- `do_action('jackpot_sync_after_purge', $post_id)` fires after cache purge.
- Multi-topic listener (`MQTT_TOPIC` comma-separated) for future feeds.
- Worker accepts a JSON array to batch multiple messages in one request.
- Scheduling: built-in Node schedule **or** external cron/PM2/systemd
  (see [SCHEDULING.md](SCHEDULING.md)).
