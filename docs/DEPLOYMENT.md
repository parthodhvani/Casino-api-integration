# Deployment

Each component deploys independently. Deploy order for a fresh environment:
WordPress plugin → Cloudflare Worker → MQTT listener.

## Secrets (generate once)

```bash
openssl rand -hex 32   # JACKPOT_SECRET  (Worker + every WordPress site)
openssl rand -hex 32   # LISTENER_SECRET (Worker + listener)
```

| Secret | Listener | Worker | WordPress |
|--------|:--------:|:------:|:---------:|
| `LISTENER_SECRET` | ✅ | ✅ | — |
| `JACKPOT_SECRET`  | — | ✅ | ✅ |

## 1. WordPress plugin (per site)

- Install + activate (see [INSTALL.md](INSTALL.md)).
- Set the shared secret (settings page or `wp-config.php`).
- Confirm `/wp-json/jackpot/v1/ping` returns JSON with `secret:true`.
- Independently upgradable: replace the plugin folder / re-upload the ZIP; no
  Worker or listener change needed unless the endpoint URL changes.

### Retention cron in production

The plugin schedules an hourly WP-Cron event. On low-traffic sites WP-Cron can
be unreliable — disable it and use a real server cron:

```php
// wp-config.php
define('DISABLE_WP_CRON', true);
```

```cron
*/15 * * * * curl -s https://YOUR-SITE/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

## 2. Cloudflare Worker

```bash
cd cloudflare-worker
npm install
npx wrangler login
npx wrangler secret put JACKPOT_SECRET
npx wrangler secret put LISTENER_SECRET
# edit wrangler.toml -> WP_SITES (start with one site)
npm run deploy            # prints https://jackpot-worker.<sub>.workers.dev
npm run tail             # live logs
```

- Independently deployable: `npm run deploy` re-publishes without touching
  WordPress or the listener.
- Add sites by editing `WP_SITES` and redeploying. One Worker fans out to all.
- Optional tuning vars: `FORWARD_RETRIES`, `FORWARD_TIMEOUT_MS`.

## 3. MQTT listener

Local (testing):

```bash
cd mqtt-listener
npm install
cp .env.example .env    # fill in values
npm start
```

Production (always-on host, e.g. Render Background Worker):

1. Root directory: `mqtt-listener`
2. Build: `npm install`
3. Start: `npm start`
4. Instance: always-on (no cold start)
5. Env vars: same as `.env` (plus optional `HEALTHCHECK_URL`, `HEARTBEAT_MS`).

The listener handles `SIGTERM` (host stop/redeploy) gracefully and
auto-reconnects to MQTT.

## Independent-deploy summary

| Component | Deploy command | Depends on (runtime) |
|-----------|----------------|----------------------|
| Plugin | ZIP upload / SFTP / WP-CLI | shared `JACKPOT_SECRET` |
| Worker | `npm run deploy` | `WP_SITES`, both secrets |
| Listener | `npm start` (host) | `WORKER_URL`, `LISTENER_SECRET` |

A change to any one component does not require redeploying the others, as long
as the shared secrets and the endpoint URL remain consistent.

## Rollback

- Plugin: re-upload the previous ZIP or restore the previous folder.
- Worker: `wrangler rollback` or redeploy the previous commit.
- Listener: redeploy the previous commit / image on the host.
