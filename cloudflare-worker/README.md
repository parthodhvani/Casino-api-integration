# Jackpot Worker (Cloudflare)

Middleware that receives normalized jackpot JSON from the MQTT listener, signs
it, and forwards it to each WordPress site.

**Single-file deploy:** `worker.js` is the complete Worker — paste it into the
Cloudflare Dashboard (no Wrangler required).

## Deploy via Cloudflare Dashboard (recommended if no Wrangler)

1. Open **Workers & Pages** → your worker → **Edit Code**.
2. Replace the editor contents with the full contents of [`worker.js`](worker.js).
3. **Save and Deploy**.
4. Set secrets under **Settings → Variables**:
   - `LISTENER_SECRET`
   - `JACKPOT_SECRET`
5. Set plain-text variables:
   - `WP_SITES` — JSON array of sites
   - `LISTENER_CONTROL_URL` — Node listener control base URL
   - Optional: `FORWARD_RETRIES`, `FORWARD_TIMEOUT_MS`, `CONTROL_TIMEOUT_MS`

## Setup (Wrangler, optional)

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

## Run / deploy (Wrangler)

```bash
npm run dev      # local dev at http://localhost:8787
npm run deploy   # deploys worker.js
npm run tail     # live logs
npm test         # unit tests
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

Optional tuning vars: `FORWARD_RETRIES`, `FORWARD_TIMEOUT_MS`, `CONTROL_TIMEOUT_MS`.

## MQTT control endpoints (v3.1)

Proxies to the Node listener (`LISTENER_CONTROL_URL`):

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/start` | Connect MQTT + subscribe |
| POST | `/stop` | Disconnect MQTT (Node process stays up) |
| GET | `/status` | Running / Stopped / last sync / connection state |

Auth (either):

- Header `x-listener-secret: <LISTENER_SECRET>` (cron / scripts), or
- Header `X-Signature: <hmac-sha256(JACKPOT_SECRET, body)>` (WordPress AJAX)

```toml
LISTENER_CONTROL_URL = "https://your-listener-host:3099"
CONTROL_TIMEOUT_MS = "8000"
```
