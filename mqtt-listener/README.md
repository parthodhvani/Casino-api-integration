# MQTT Listener (Node 14+ / cPanel)

Holds the MQTT connection to HiveMQ (when started), parses DRGT messages, and
forwards normalized JSON to the Cloudflare Worker.

**Compatible with Node.js 14** (cPanel default). Also runs on 16/18/20.

```
MQTT Broker → mqtt-listener (this app) → Cloudflare Worker → WordPress plugin
```

Control API and message contract are unchanged — works with the existing
Worker (`worker.js`) and WordPress Jackpot Sync plugin.

## Requirements

- **Node.js >= 14**
- Outbound HTTPS to your Worker URL
- Outbound MQTTS to HiveMQ (port 8883)
- Open inbound TCP for the control port (default `3099`) if the Worker proxies `/start|/stop|/status`

## Setup (cPanel)

```bash
cd mqtt-listener
# Select Node 14 in cPanel → Setup Node.js App (or nvm use 14)
npm install
cp .env.example .env
# edit .env — set WORKER_URL + LISTENER_SECRET to match the Worker
npm start
```

## Environment

Same as before. Critical values that must match the Worker / plugin:

| Env | Must match |
|-----|------------|
| `WORKER_URL` | Your Cloudflare Worker URL |
| `LISTENER_SECRET` | Worker secret `LISTENER_SECRET` |
| MQTT_* | HiveMQ broker credentials |

Optional schedule / control: see `.env.example`.

## Control API

```bash
curl -X POST -H "x-listener-secret: $LISTENER_SECRET" http://127.0.0.1:3099/start
curl -H "x-listener-secret: $LISTENER_SECRET" http://127.0.0.1:3099/status
curl -X POST -H "x-listener-secret: $LISTENER_SECRET" http://127.0.0.1:3099/stop
```

## Node 14 changes (vs previous 18+)

| Before (Node 18+) | Now (Node 14+) |
|-------------------|----------------|
| Built-in `fetch` | `node-fetch@2` |
| Built-in `AbortController` | `abort-controller` |
| `mqtt@5` | `mqtt@4` |
| `node --test` | `node test/run-tests.js` |

Worker + WordPress contracts are identical (headers, JSON body, HMAC path via Worker).

## Test

```bash
npm test
```

## Structure

```
src/
  index.js           entry
  mqtt-manager.js    startMQTT / stopMQTT / getStatus
  control-server.js  HTTP /start /stop /status
  scheduler.js       daily 06:00 / 08:00
  forwarder.js       POST to Cloudflare Worker
  http.js            fetch + AbortController (Node 14 polyfills)
  parser.js          JPCONFIG / JPUPDATE
  config.js / logger.js / security.js
```
