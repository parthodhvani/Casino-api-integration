# MQTT Listener (Node + HiveMQ)

Holds the MQTT connection to HiveMQ (when started), parses DRGT messages, and
forwards normalized JSON to the Cloudflare Worker.

The Node **process** stays running. MQTT connect/disconnect is controlled via
HTTP (`POST /start`, `POST /stop`, `GET /status`) or the built-in daily schedule
(06:00–08:00).

- **Testing:** run on your laptop (free).
- **Production:** run on an always-on host with port `3099` reachable from Cloudflare.

## Structure

```
src/
  index.js           entry (control server + scheduler; MQTT idle until /start)
  mqtt-manager.js    startMQTT / stopMQTT / isRunning / getStatus
  control-server.js  HTTP control API
  scheduler.js       daily 06:00 / 08:00 window
  security.js        constant-time secret compare
  config.js          env var loading + validation
  parser.js          DRGT message parsing (JPCONFIG / JPUPDATE)
  forwarder.js       sends normalized JSON to the Worker (retry + timeout)
  logger.js          structured single-line JSON logging
test/
  parser.test.js
  forwarder.test.js
  mqtt-control.test.js
```

## Setup

```bash
cd mqtt-listener
npm install
cp .env.example .env
# edit .env with your values
```

Requires **Node.js 18+** (uses the built-in `fetch`).

## Run

```bash
npm start     # start process + control API (MQTT idle by default)
npm test      # unit tests (no network needed)
```

Control examples:

```bash
curl -X POST -H "x-listener-secret: $LISTENER_SECRET" http://127.0.0.1:3099/start
curl -H "x-listener-secret: $LISTENER_SECRET" http://127.0.0.1:3099/status
curl -X POST -H "x-listener-secret: $LISTENER_SECRET" http://127.0.0.1:3099/stop
```

You should see:

```json
{"level":"info","ts":"...","message":"control server listening","port":3099}
{"level":"info","ts":"...","message":"mqtt start requested","broker":"mqtts://...:8883"}
{"level":"info","ts":"...","message":"mqtt connected","broker":"mqtts://...:8883"}
{"level":"info","ts":"...","message":"mqtt subscribed","topics":["/jp/gent"]}
```

Scheduling (cron / PM2 / systemd): see [`docs/SCHEDULING.md`](../docs/SCHEDULING.md).

## Deploy notes

The control port must be reachable from the Cloudflare Worker
(`LISTENER_CONTROL_URL`). Use a Web Service / VPS with an open port — a
Render “Background Worker” without HTTP ingress cannot receive `/start` from
the Worker (built-in schedule still works on that host).
