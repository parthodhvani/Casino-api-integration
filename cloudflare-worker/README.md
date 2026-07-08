# Jackpot Worker (Cloudflare)

Middleware that receives normalized jackpot JSON from the MQTT listener, signs
it, and forwards it to each WordPress site.

## Setup

```bash
cd cloudflare-worker
npm install
npx wrangler login
```

## Configure secrets

```bash
npx wrangler secret put JACKPOT_SECRET     # same value as WordPress wp-config.php
npx wrangler secret put LISTENER_SECRET    # a second random value for the listener
```

## Configure endpoints

Edit `wrangler.toml` -> `WP_ENDPOINTS`. Comma-separate multiple sites:

```toml
WP_ENDPOINTS = "https://siteA/wp-json/jackpot/v1/update,https://siteB/wp-json/jackpot/v1/update"
```

## Run / deploy

```bash
npm run dev      # local dev at http://localhost:8787
npm run deploy   # deploy; prints your https://jackpot-worker.<sub>.workers.dev URL
npm run tail     # live logs
```

## Contract

- Accepts `POST` with header `x-listener-secret: <LISTENER_SECRET>`.
- Body is any JSON; it is forwarded verbatim to each WordPress endpoint.
- Adds header `x-signature: <hmac-sha256(JACKPOT_SECRET, body)>`.
- Returns `200` if all sites succeeded, `207` if some failed (per-site detail in `results`).
