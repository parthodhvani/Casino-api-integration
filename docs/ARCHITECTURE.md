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

## Why a listener AND a Worker?

A Cloudflare Worker is event-driven and cannot hold a permanent MQTT connection
open. The small Node listener keeps the MQTT session alive; the Worker does the
authentication, validation, signing and fan-out to the sites. This split also
means the signing secret never lives on the always-on listener host.

## Components

### 1. MQTT Listener (`mqtt-listener/`)

| File | Responsibility |
|------|----------------|
| `src/index.js` | Wires the MQTT connection, heartbeat, and graceful shutdown. |
| `src/config.js` | Loads + validates env vars (multi-topic, tuning knobs). |
| `src/parser.js` | Converts semicolon messages to normalized JSON (never throws). |
| `src/forwarder.js` | POSTs to the Worker with timeout + exponential-backoff retry. |
| `src/logger.js` | Structured single-line JSON logging. |

Behavior: auto-reconnect, per-message forward with retry, periodic heartbeat
log, optional dead-man's-switch ping, clean `SIGINT`/`SIGTERM` shutdown.

### 2. Cloudflare Worker (`cloudflare-worker/`)

| File | Responsibility |
|------|----------------|
| `src/index.js` | Entry: auth → validate → sign → fan-out → aggregate. |
| `src/hmac.js` | `hmacSha256Hex` + constant-time `timingSafeEqual`. |
| `src/sites.js` | Parse `WP_SITES` into normalized descriptors. |
| `src/validator.js` | Validate a message + normalize body (single or batch). |
| `src/forwarder.js` | Per-site POST with timeout + retry (5xx retried, 4xx not). |

Behavior: constant-time listener-secret check, payload validation at the edge,
per-message per-site HMAC signature, batching (array payloads), retry/timeout,
structured logs, aggregated metrics + per-site results.

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
| `Jackpot_Sync_Admin` | Settings page, dashboard, Tools → Jackpot Tester. |
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
