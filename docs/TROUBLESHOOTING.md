# Troubleshooting

## Plugin

### “No valid plugins were found” on ZIP upload

The ZIP was corrupted or wrongly structured. Fixes, in order:

1. Re-download with GitHub **“Download raw file”** (it's a binary; this repo
   marks `*.zip` binary via `.gitattributes`).
2. Verify structure — must be a single top-level `jackpot-sync/` folder:
   ```bash
   unzip -l jackpot-sync-v3.0.0.zip
   ```
3. Rebuild it:
   ```bash
   cd wordpress-plugin && zip -rX jackpot-sync-v3.0.0.zip jackpot-sync
   ```
4. Or SFTP the `jackpot-sync` folder to `wp-content/plugins/` and activate.

### `/ping` returns HTML / a WAF error instead of JSON

The host WAF is blocking the REST route. Ask the host to allowlist
`…/wp-json/jackpot/v1/update` for the Worker's outbound IPs and confirm the
`X-Signature` header is not stripped.

### 401 / “signature mismatch”

- The plugin secret must be **exactly** the Worker's `JACKPOT_SECRET` (no extra
  spaces, full length). Re-paste both.
- If a site uses a per-site `secret` in `WP_SITES`, that same value must be the
  site's secret.
- Confirm the WAF isn't altering the request body (which would change the HMAC).

### JPUPDATE returns 202 “jackpot not found”

A `JPUPDATE` arrived before the `JPCONFIG` that creates the post. Send the
`JPCONFIG` first (or use **Tools → Jackpot Tester**). This is expected behavior.

### Values look wrong (×100)

Wrong value format. On the settings page pick **Cents** if `53467` means
`€534.67`, or **Whole units** if it means `€53,467`.

### Featured image not set

Images are only resolved from the **local Media Library** (never downloaded).
Ensure an attachment exists whose **title** equals `jpName`, or whose
**filename** is `<jpId>.webp|jpg|jpeg|png`. Switch the **image matching mode**
accordingly. A missing image never blocks creation — it's logged as a warning
and shown in the dashboard's “Images Missing” counter.

### Retention not running

Check the “Retention cron” health row. On low-traffic sites WP-Cron may stall —
use a real server cron (see [DEPLOYMENT.md](DEPLOYMENT.md)).

## Cloudflare Worker

### 401 unauthorized

The `x-listener-secret` sent by the listener must equal the Worker's
`LISTENER_SECRET`. Re-set with `wrangler secret put LISTENER_SECRET`.

### 500 “no WP_SITES configured”

`WP_SITES` in `wrangler.toml` is empty or not valid JSON. It must be a JSON
array string of `{name,url,secret?}` objects.

### 207 responses

At least one site or message failed. Inspect the `results`/`rejected` arrays in
the JSON body and `npm run tail` logs. 5xx site errors are retried; 4xx are not.

### npm audit warnings

Build tooling is pinned to Wrangler v4, which resolves all advisories
(`npm audit` → 0 vulnerabilities). If you re-pin to v3 the dev-only esbuild/
undici/ws advisories return.

## MQTT Listener

### `[fatal] Missing required env var`

Copy `.env.example` to `.env` and fill in all required values.

### Connects but no messages

Confirm `MQTT_TOPIC` matches the broker's topic. The heartbeat log shows
`messages`/`forwarded` counters and `lastMessageAt` — a silent feed keeps
`lastMessageAt` null.

### Worker rejects forwards (4xx in logs)

The listener does not retry 4xx (bad payload/auth). Fix the `LISTENER_SECRET`
or the payload; 5xx and network errors are retried with backoff.
