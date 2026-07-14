# Casino Real-time Jackpot Sync (v3.1.0)

Fully automated, real-time jackpot pipeline for WordPress casino sites. It
replaces manual ACF entry with an end-to-end automated stream: DRGT publishes
jackpot messages over MQTT, a small listener forwards them to a Cloudflare
Worker, the Worker signs and fans them out to each WordPress site, and the
`jackpot-sync` plugin creates/updates the Jackpot CPT + ACF fields — which the
existing Elementor carousel renders automatically.

WordPress never talks to MQTT directly. MQTT connect/disconnect is controllable
via Worker + admin UI (and a daily 06:00–08:00 schedule).

## Architecture

```
[DRGT Casino System]
        │ MQTT (HiveMQ, semicolon-separated messages)
        ▼
[MQTT Listener]  (Node — controllable start/stop, reconnect + retry + heartbeat)
        │ HTTPS POST (normalized JSON + x-listener-secret)
        ▼
[Cloudflare Worker]  (auth, validate, HMAC-sign, retry, fan-out + MQTT control proxy)
        │ HTTPS POST (JSON + X-Signature: HMAC-SHA256)
        ▼
[WordPress REST]  /wp-json/jackpot/v1/update   (jackpot-sync plugin, ×N sites)
        │ match on jpId → create (JPCONFIG) or update ACF (JPUPDATE)
        ▼
[Jackpot CPT] → [ACF] → [Elementor Carousel]
```

MQTT control: `WP Admin / Cron → Worker (/start|/stop|/status) → Node → MQTT`

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) and
[`docs/SCHEDULING.md`](docs/SCHEDULING.md).

## Repository layout

| Folder | Stack | What it is |
|--------|-------|------------|
| `wordpress-plugin/jackpot-sync/` | WordPress/PHP | Signed REST endpoint, modular services, admin dashboard + MQTT controls + tester. Configured from **Settings → Jackpot Sync**. |
| `wordpress-plugin/tests/` | PHP | In-memory functional test suite for the plugin (no WP install needed). |
| `cloudflare-worker/` | Cloudflare | Authenticates the listener, validates + signs payloads, fans out, proxies MQTT control. Includes `node:test` suite. |
| `mqtt-listener/` | Node + HiveMQ | Controllable MQTT connection, HTTP `/start` `/stop` `/status`, daily schedule, forwards to Worker. |
| `tools/` | Node | `simulate-message.js` — send a fake message to the Worker (or directly to WordPress) to test without live MQTT. |
| `docs/` | — | Full documentation set (setup, install, testing, deployment, scheduling, security, troubleshooting, changelog). |

## Quick start

1. **Plugin** — upload `wordpress-plugin/jackpot-sync` and activate, then set the
   shared secret + Worker URL on **Settings → Jackpot Sync**. See [`docs/INSTALL.md`](docs/INSTALL.md).
2. **Worker** — set `JACKPOT_SECRET` + `LISTENER_SECRET`, configure `WP_SITES` +
   `LISTENER_CONTROL_URL`, `npm install && npm run deploy`.
3. **Listener** — copy `.env.example` to `.env`, fill in values, `npm install && npm start`.
   MQTT stays idle until `/start`, the schedule, or `MQTT_AUTO_START=true`.

Full walkthrough: [`docs/SETUP.md`](docs/SETUP.md).

## Message formats

```
JPCONFIG;<jpId>;<level>;<jpType>;<jpName>;<prizeName>;<CasID>
JPUPDATE;<jpId>;<level>;<_>;<_>;<jpValue>;<jpShared>;<_>;<CasID>
```

Examples:

```
JPCONFIG;O136;2;11;Huff n Puff Mystery LVL2;;IFCO
JPUPDATE;O136;2;0;217;53467;867;0;IFCO
```

- **JPCONFIG** → create the jackpot post if it doesn't exist (matched on `jpId`),
  store hidden + legacy meta, assign a featured image from the **local Media
  Library** (never downloaded).
- **JPUPDATE** → update ACF fields `amount` and `shared_profit_amount`, then
  purge caches.

## Testing

```bash
# Plugin (PHP)
php wordpress-plugin/tests/run-tests.php

# Worker (Node)
cd cloudflare-worker && npm test

# Listener (Node)
cd mqtt-listener && npm test
```

See [`docs/TESTING.md`](docs/TESTING.md) for the full test + manual QA guide.

## Security

- Listener → Worker: shared `x-listener-secret` (constant-time compare).
- Worker → WordPress: HMAC-SHA256 `X-Signature` over the exact body bytes,
  verified with `hash_equals` (constant-time).
- MQTT control: same listener secret and/or HMAC with `JACKPOT_SECRET`.
  Details in [`docs/SECURITY.md`](docs/SECURITY.md).

## Documentation

- [SETUP.md](docs/SETUP.md) — end-to-end setup (test one site → roll out to all)
- [INSTALL.md](docs/INSTALL.md) — plugin install options
- [TESTING.md](docs/TESTING.md) — automated + manual testing
- [DEPLOYMENT.md](docs/DEPLOYMENT.md) — deploy each component independently
- [SCHEDULING.md](docs/SCHEDULING.md) — daily 06:00/08:00 + Cron / PM2 / Systemd
- [ARCHITECTURE.md](docs/ARCHITECTURE.md) — data flow + component design
- [SECURITY.md](docs/SECURITY.md) — threat model + signature scheme
- [TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) — common issues
- [CHANGELOG.md](docs/CHANGELOG.md) — version history

## Open questions to confirm with the client / DRGT

1. Is `jpValue` (e.g. `53467`) in euros or cents? (set the plugin's value format)
2. Are the test broker/topic the same as production, or new credentials at go-live?
3. A `JPUPDATE` arriving before its `JPCONFIG` is currently skipped (HTTP 202).
   Confirm this is the desired behavior.

> Note: the MQTT credentials in `.env.example` are the test credentials from the
> brief. Rotate them before production.
