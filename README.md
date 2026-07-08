# Casino Real-time Jackpot API Integration

Automates the jackpot data stream for three Belgian casino WordPress sites
(Oostende, Berck, Dinant). Replaces manual ACF entry with a real-time pipeline.

## Architecture

```
[DRGT Casino System]
        │ MQTT (HiveMQ, semicolon-separated messages)
        ▼
[MQTT Listener]  (persistent connection — laptop for test, host for prod)
        │ HTTPS POST (normalized JSON + listener secret)
        ▼
[Cloudflare Worker]  (signs payload, fans out to all sites)
        │ HTTPS POST (JSON + HMAC X-Signature)
        ▼
[WordPress REST endpoint]  ×3 sites  (jackpot-sync plugin)
        │ match on jpId + casId, update ACF
        ▼
[Live Elementor Carousel]
```

**Why a listener + a Worker?** A plain Cloudflare Worker is event-driven and
cannot hold a permanent MQTT connection open. The small listener keeps the MQTT
session alive; the Worker does the signing and fan-out to the 3 sites.

## Repository layout

| Folder | What it is |
|--------|------------|
| `mqtt-listener/` | Node.js service that holds the MQTT connection and forwards messages. |
| `cloudflare-worker/` | Cloudflare Worker that signs and fans out to WordPress sites. |
| `wordpress-plugin/jackpot-sync/` | WordPress plugin: signed REST endpoint + ACF writes + retention cron. |

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

- **JPCONFIG** → create the jackpot post if it doesn't exist (matched on `jpId + casId`).
- **JPUPDATE** → update ACF fields `jackpot_amount` and `shared_profit_amount`.

## Security model

- The listener authenticates to the Worker with a shared header `x-listener-secret` (`LISTENER_SECRET`).
- The Worker signs each forwarded body with HMAC-SHA256 (`JACKPOT_SECRET`).
- WordPress rejects any request whose `X-Signature` doesn't match.

Generate each secret with:

```bash
openssl rand -hex 32
```

## What to buy (production)

| Item | Needed? | Cost |
|------|---------|------|
| Cloudflare Workers Paid | Recommended | ~$5/mo |
| Always-on listener host (Render/Fly/VPS) | Yes | ~$5-7/mo |
| HiveMQ broker | No — DRGT owns it | $0 |
| WordPress REST API / ACF | No new purchase | $0 |

**Total ≈ $10-12/month, shared across all 3 sites.**

For **testing on one site you can run entirely free** (Workers free tier +
listener on your laptop + existing WordPress/ACF).

## Setup

See [`SETUP.md`](SETUP.md) for the full step-by-step guide (test on one site
first, then roll out to all three).

## Open questions to confirm with the client / DRGT

1. Is `jpValue` (e.g. `53467`) in euros or cents? (set `JACKPOT_VALUE_DIVISOR` accordingly)
2. Are the test broker/topic the same as production, or will you get new credentials at go-live?
3. Should a `JPUPDATE` arriving before its `JPCONFIG` be created automatically or skipped? (current behavior: skipped, returns 202)

> Note: the MQTT credentials in `.env.example` are the test credentials from the
> brief. Rotate them before production.
