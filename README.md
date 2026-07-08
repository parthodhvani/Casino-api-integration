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

Each part is fully self-contained so you can develop, test, and deploy them
independently.

| Folder | Stack | What it is |
|--------|-------|------------|
| `mqtt-listener/` | Node + HiveMQ | Holds the MQTT connection, parses messages, forwards to the Worker. Modular `src/` + tests. |
| `cloudflare-worker/` | Cloudflare | Signs each payload and fans out to all WordPress sites (per-site config). |
| `wordpress-plugin/jackpot-sync/` | WordPress/PHP | Signed REST endpoint + ACF writes + cache purge + retention cron. |
| `tools/` | Node | `simulate-message.js` — send a fake message to the Worker to test without live MQTT. |

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
- The Worker signs each forwarded body with HMAC-SHA256 and WordPress verifies the `X-Signature`.
- WordPress rejects any request whose signature doesn't match.

Generate each secret once with:

```bash
openssl rand -hex 32
```

### Where the two secrets live

| Secret | Listener | Worker | WordPress (all 3 sites) |
|--------|:--------:|:------:|:-----------------------:|
| `LISTENER_SECRET` | ✅ | ✅ | — |
| `JACKPOT_SECRET`  | — | ✅ | ✅ |

- You generate the secrets **once** — not once per site.
- `JACKPOT_SECRET`: the **same value** goes in the Worker and in every site's
  `wp-config.php`. (Optional: give each site its own secret via `WP_SITES` for
  stronger isolation — see `cloudflare-worker/README.md`.)
- **You (the developer) set these up** in both test and production. The client
  never runs these commands; they only approve the budget and grant access.
  On your laptop = testing; on the always-on host = production. Same steps.

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
