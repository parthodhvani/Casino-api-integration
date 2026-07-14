# Testing

## Automated tests

### WordPress plugin (PHP)

A self-contained functional suite runs the full create/update/skip/validate/
format/image/retention pipeline against in-memory WordPress stubs — **no WP
install required**.

```bash
php wordpress-plugin/tests/run-tests.php
```

What it covers:

- Message parsing (JPCONFIG/JPUPDATE, malformed rejection)
- Payload validation (missing/invalid fields, non-numeric values)
- Prize formatter (whole units vs cents, non-numeric → 0)
- JPCONFIG create (post published, title = jpName, hidden + legacy meta)
- JPCONFIG duplicate → skip (no duplicate post)
- Image resolver (by jpName title, by jpId filename)
- JPUPDATE (ACF/meta values with cents conversion)
- JPUPDATE before JPCONFIG → 202 skip
- Retention (keep newest N, delete the rest)
- HMAC signature parity + constant-time compare
- MQTT control settings defaults + sanitize (`worker_url`)
- MQTT status cache + time formatting helpers

Lint every PHP file:

```bash
find wordpress-plugin/jackpot-sync -name '*.php' -exec php -l {} \;
```

### Cloudflare Worker (Node)

```bash
cd cloudflare-worker && npm install && npm test
```

Covers HMAC (incl. a known vector), constant-time compare, `WP_SITES` parsing,
message validation, body parsing (single + batch), forwarder retry/timeout
behavior (5xx retried, 4xx not, no-secret error), and control proxy
(`forwardControl` URL required, status proxy, network → 502).

Build check:

```bash
cd cloudflare-worker && npx wrangler deploy --dry-run
```

### MQTT Listener (Node)

```bash
cd mqtt-listener && npm install && npm test
```

Covers parser, forwarder retry/timeout, MQTT manager start/stop/already_running,
control-server auth + routes, scheduler `zonedParts`, timing-safe secret compare.

## Manual / end-to-end tests

### 1. In-WordPress tester (no MQTT needed)

**Tools → Jackpot Tester**. Paste a raw line and click **Process** — it runs
through the exact same processor as the REST endpoint:

```
JPCONFIG;O136;2;11;Huff n Puff Mystery LVL2;;IFCO
JPUPDATE;O136;2;0;217;53467;867;0;IFCO
```

Verify: the jackpot post is created (first), then `amount` and
`shared_profit_amount` update (second). The runtime dashboard on
**Settings → Jackpot Sync** shows created/updated/skipped/images/errors.

### 2. Simulate through the Worker

```bash
WORKER_URL="https://jackpot-worker.<sub>.workers.dev" \
LISTENER_SECRET="your-listener-secret" \
node tools/simulate-message.js config     # create

WORKER_URL="https://jackpot-worker.<sub>.workers.dev" \
LISTENER_SECRET="your-listener-secret" \
node tools/simulate-message.js update      # update
```

### 3. Simulate directly to WordPress (bypass Worker)

```bash
WP_URL="https://YOUR-SITE/wp-json/jackpot/v1/update" \
JACKPOT_SECRET="your-secret" \
node tools/simulate-message.js update --direct
```

### 4. MQTT Start / Stop / Status

**Listener direct:**

```bash
# Process is up, MQTT idle
curl -s http://127.0.0.1:3099/health

# Start
curl -s -X POST -H "x-listener-secret: $LISTENER_SECRET" http://127.0.0.1:3099/start
# → {"status":"started",...}

# Already running
curl -s -X POST -H "x-listener-secret: $LISTENER_SECRET" http://127.0.0.1:3099/start
# → {"status":"already_running"}

# Status
curl -s -H "x-listener-secret: $LISTENER_SECRET" http://127.0.0.1:3099/status

# Stop (process stays alive)
curl -s -X POST -H "x-listener-secret: $LISTENER_SECRET" http://127.0.0.1:3099/stop
```

**Via Worker:**

```bash
curl -s -X POST -H "x-listener-secret: $LISTENER_SECRET" \
  https://jackpot-worker.EXAMPLE.workers.dev/start
curl -s -H "x-listener-secret: $LISTENER_SECRET" \
  https://jackpot-worker.EXAMPLE.workers.dev/status
curl -s -X POST -H "x-listener-secret: $LISTENER_SECRET" \
  https://jackpot-worker.EXAMPLE.workers.dev/stop
```

**WordPress admin:**

1. Set **Shared secret** + **Cloudflare Worker URL** on Settings → Jackpot Sync.
2. Click **Refresh Status** — UI updates without reload.
3. Click **Start MQTT** — indicator turns Running (green).
4. Click **Stop MQTT** — indicator turns Stopped (red).
5. Wrong secret / unreachable Worker → red admin notice with a clear message.

### 5. Automatic 06:00 / 08:00 schedule

**Built-in:** set `SCHEDULE_START=06:00`, `SCHEDULE_STOP=08:00`, `SCHEDULE_TZ=…`,
`SCHEDULE_ENABLED=true`. Watch logs around those times for
`scheduler start window` / `scheduler stop window`.

**External:** temporarily set times a few minutes ahead, or use cron examples in
[SCHEDULING.md](SCHEDULING.md). Confirm `GET /status` flips Running → Stopped
while `GET /health` (and `ps`) still show the Node process alive.

### 6. Full pipeline checklist

1. Start MQTT (admin or `/start`).
2. Listener terminal → `message received` then `forwarded to worker`.
3. Worker logs (`npm run tail`) → incoming POST + per-site results.
4. WordPress → `wp-admin/edit.php?post_type=jackpot`: JPCONFIG creates a post,
   JPUPDATE changes the two ACF values.
5. Front-end carousel updates after cache purge.
6. Stop MQTT — messages stop; process + control API remain up.

### Euros vs cents

Compare one live `jpValue` against the displayed jackpot:
`€534.67` from `53467` → choose **Cents**; `€53,467` → choose **Whole units**.
