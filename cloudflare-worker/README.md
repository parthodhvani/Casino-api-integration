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

## Configure sites

Edit `wrangler.toml` -> `WP_SITES` (a JSON array). Start with one site, add more later:

```toml
# one shared secret for all sites (simple):
WP_SITES = '[{"name":"berck","url":"https://siteA/wp-json/jackpot/v1/update"}]'

# multiple sites, optional per-site secret (stronger isolation):
WP_SITES = '[
  {"name":"berck","url":"https://siteA/wp-json/jackpot/v1/update"},
  {"name":"oostende","url":"https://siteB/wp-json/jackpot/v1/update","secret":"site-b-secret"},
  {"name":"dinant","url":"https://siteC/wp-json/jackpot/v1/update"}
]'
```

- No `secret` on a site → the Worker signs with the shared `JACKPOT_SECRET`.
- A site with its own `secret` → that site's `wp-config.php` must use the same value.

## Run / deploy

```bash
npm run dev      # local dev at http://localhost:8787
npm run deploy   # deploy; prints your https://jackpot-worker.<sub>.workers.dev URL
npm run tail     # live logs
```

## Contract

- Accepts `POST` with header `x-listener-secret: <LISTENER_SECRET>` (checked in
  constant time).
- Body is a JSON message object, or a JSON array of messages (batching).
- Each message is validated (`type`, `jpId`, `casId`, required fields). Invalid
  messages are reported in `rejected`; a batch with none valid returns `400`.
- For each valid message and each site, adds
  `x-signature: <hmac-sha256(site secret or JACKPOT_SECRET, JSON.stringify(message))>`
  and POSTs it, retrying network/5xx errors with backoff + timeout.
- Returns `200` if everything succeeded, `207` if any site/message failed
  (per-message, per-site detail in `results`), plus a `metrics` summary.

Optional tuning vars (`wrangler.toml`): `FORWARD_RETRIES`, `FORWARD_TIMEOUT_MS`.
