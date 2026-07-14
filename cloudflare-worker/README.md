# Jackpot Worker (Cloudflare)

**One file only:** [`worker.js`](worker.js)

Paste it into Cloudflare Dashboard → Workers & Pages → Edit Code (replace the
Hello World template), then Save and Deploy.

```
MQTT Listener (Node)
        |
        | HTTP POST + x-listener-secret
        ↓
worker.js  (this file — HMAC fan-out + /start /stop /status)
        |
        | HMAC signed requests
        ↓
WordPress REST APIs
```

## Dashboard setup

1. Open your Worker → **Edit Code**.
2. Delete the Hello World code.
3. Paste the entire contents of `worker.js`.
4. **Save and Deploy**.
5. **Settings → Variables**:
   - Secrets: `LISTENER_SECRET`, `JACKPOT_SECRET`
   - Text: `WP_SITES` (JSON array), `LISTENER_CONTROL_URL`
   - Optional: `FORWARD_RETRIES`, `FORWARD_TIMEOUT_MS`, `CONTROL_TIMEOUT_MS`

### Example `WP_SITES`

```json
[
  {"name":"site1","url":"https://example.com/wp-json/jackpot/v1/update"},
  {"name":"site2","url":"https://other.com/wp-json/jackpot/v1/update","secret":"optional-per-site"}
]
```

## Routes (all in worker.js)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/` | Health `{ ok, service, version }` |
| POST | `/` | Fan-out jackpot messages to WordPress |
| GET | `/status` | Proxy MQTT status to Node listener |
| POST | `/start` | Proxy MQTT start |
| POST | `/stop` | Proxy MQTT stop |

## Optional Wrangler

```bash
cd cloudflare-worker
npm install
npm test
npm run deploy   # deploys worker.js
```

`wrangler.toml` already sets `main = "worker.js"`.
