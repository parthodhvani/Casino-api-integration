# MQTT Scheduling & Control

The Node MQTT listener process stays running 24/7. Only the **MQTT connection**
starts and stops. Default window:

| Action | Time | Effect |
|--------|------|--------|
| Start  | 06:00 | `startMQTT()` — connect + subscribe |
| Stop   | 08:00 | `stopMQTT()` — disconnect gracefully |

Three ways to drive the schedule (pick one; do not stack duplicates):

1. **Built-in scheduler** (default) — Node handles 06:00/08:00 itself.
2. **External cron / PM2 / systemd** — call the HTTP control API.
3. **WordPress admin buttons** — manual Start / Stop any time.

## Built-in scheduler (recommended)

Env vars on the listener (see `.env.example`):

```bash
SCHEDULE_ENABLED=true
SCHEDULE_START=06:00
SCHEDULE_STOP=08:00
SCHEDULE_TZ=Europe/Paris
```

Set `SCHEDULE_ENABLED=false` if you prefer external cron only.

## HTTP control API

Listener (authenticated with `x-listener-secret: $LISTENER_SECRET`):

```bash
POST http://LISTENER_HOST:3099/start
POST http://LISTENER_HOST:3099/stop
GET  http://LISTENER_HOST:3099/status
GET  http://LISTENER_HOST:3099/health   # open liveness probe
```

Via Cloudflare Worker (same secret header, or HMAC with `JACKPOT_SECRET`):

```bash
POST https://jackpot-worker.<sub>.workers.dev/start
POST https://jackpot-worker.<sub>.workers.dev/stop
GET  https://jackpot-worker.<sub>.workers.dev/status
```

### Example responses

```json
// POST /start when already connected
{ "status": "already_running" }

// POST /start when stopped
{ "status": "started", "connectionState": "connecting" }

// GET /status
{
  "status": "Running",
  "running": true,
  "connectionState": "connected",
  "lastSyncTime": "2026-07-14T06:42:00.000Z",
  "lastMessageAt": "2026-07-14T06:55:00.000Z",
  "lastConfigUpdate": "2026-07-14T06:10:00.000Z"
}
```

---

## Linux Cron examples

Hit the **Worker** (reachable from anywhere) or the listener directly if the
cron host can reach it.

```cron
# /etc/cron.d/jackpot-mqtt  (Europe/Paris wall clock — set TZ on the host)
SHELL=/bin/bash
PATH=/usr/bin:/bin
MAILTO=""

# 06:00 — start MQTT
0 6 * * * root curl -fsS -X POST \
  -H "x-listener-secret: ${LISTENER_SECRET}" \
  https://jackpot-worker.EXAMPLE.workers.dev/start \
  >/var/log/jackpot-mqtt-start.log 2>&1

# 08:00 — stop MQTT
0 8 * * * root curl -fsS -X POST \
  -H "x-listener-secret: ${LISTENER_SECRET}" \
  https://jackpot-worker.EXAMPLE.workers.dev/stop \
  >/var/log/jackpot-mqtt-stop.log 2>&1
```

Direct-to-listener variant:

```cron
0 6 * * * curl -fsS -X POST -H "x-listener-secret: SECRET" http://127.0.0.1:3099/start
0 8 * * * curl -fsS -X POST -H "x-listener-secret: SECRET" http://127.0.0.1:3099/stop
```

If you use cron, disable the built-in schedule:

```bash
SCHEDULE_ENABLED=false
```

---

## PM2 examples

### Keep the Node process alive

```bash
cd /opt/jackpot/mqtt-listener
pm2 start src/index.js --name jackpot-mqtt --time
pm2 save
pm2 startup
```

### Cron-like MQTT window via PM2

`ecosystem.config.cjs`:

```js
module.exports = {
  apps: [
    {
      name: 'jackpot-mqtt',
      script: 'src/index.js',
      cwd: '/opt/jackpot/mqtt-listener',
      env: {
        SCHEDULE_ENABLED: 'false', // external cron below
      },
    },
    {
      name: 'jackpot-mqtt-start',
      script: 'curl',
      args: '-fsS -X POST -H x-listener-secret:SECRET http://127.0.0.1:3099/start',
      cron_restart: '0 6 * * *',
      autorestart: false,
    },
    {
      name: 'jackpot-mqtt-stop',
      script: 'curl',
      args: '-fsS -X POST -H x-listener-secret:SECRET http://127.0.0.1:3099/stop',
      cron_restart: '0 8 * * *',
      autorestart: false,
    },
  ],
};
```

```bash
pm2 start ecosystem.config.cjs
pm2 save
```

Prefer the built-in scheduler with a single PM2 app unless you need central cron.

---

## Systemd examples

### Service unit (always-on Node process)

`/etc/systemd/system/jackpot-mqtt.service`:

```ini
[Unit]
Description=Jackpot MQTT Listener
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=jackpot
WorkingDirectory=/opt/jackpot/mqtt-listener
EnvironmentFile=/opt/jackpot/mqtt-listener/.env
ExecStart=/usr/bin/node src/index.js
Restart=always
RestartSec=5
# Expose control port; ensure firewall allows Cloudflare → :3099 if using Worker proxy.
AmbientCapabilities=

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable --now jackpot-mqtt
systemctl status jackpot-mqtt
```

### Timer units (optional external schedule)

`/etc/systemd/system/jackpot-mqtt-start.service`:

```ini
[Unit]
Description=Start Jackpot MQTT connection

[Service]
Type=oneshot
EnvironmentFile=/opt/jackpot/mqtt-listener/.env
ExecStart=/usr/bin/curl -fsS -X POST -H "x-listener-secret: ${LISTENER_SECRET}" http://127.0.0.1:3099/start
```

`/etc/systemd/system/jackpot-mqtt-start.timer`:

```ini
[Unit]
Description=Daily 06:00 MQTT start

[Timer]
OnCalendar=*-*-* 06:00:00
Persistent=true

[Install]
WantedBy=timers.target
```

`/etc/systemd/system/jackpot-mqtt-stop.service`:

```ini
[Unit]
Description=Stop Jackpot MQTT connection

[Service]
Type=oneshot
EnvironmentFile=/opt/jackpot/mqtt-listener/.env
ExecStart=/usr/bin/curl -fsS -X POST -H "x-listener-secret: ${LISTENER_SECRET}" http://127.0.0.1:3099/stop
```

`/etc/systemd/system/jackpot-mqtt-stop.timer`:

```ini
[Unit]
Description=Daily 08:00 MQTT stop

[Timer]
OnCalendar=*-*-* 08:00:00
Persistent=true

[Install]
WantedBy=timers.target
```

```bash
# If using timers, set SCHEDULE_ENABLED=false in .env
systemctl enable --now jackpot-mqtt-start.timer jackpot-mqtt-stop.timer
systemctl list-timers | grep jackpot
```

---

## Production checklist

1. Listener host is always-on (no sleep / cold start).
2. Control port `3099` (or `CONTROL_PORT`) is reachable from Cloudflare Workers
   when using Worker proxy — set `LISTENER_CONTROL_URL` in `wrangler.toml`.
3. `LISTENER_SECRET` matches on listener, Worker, and cron scripts.
4. Prefer **one** scheduler source (built-in **or** cron/PM2/systemd).
5. WordPress **Settings → Jackpot Sync** has Worker URL + shared secret for admin buttons.
