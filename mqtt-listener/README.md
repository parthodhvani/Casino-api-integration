# MQTT Listener (Node + HiveMQ)

Holds the persistent connection to HiveMQ, parses DRGT messages, and forwards
normalized JSON to the Cloudflare Worker.

- **Testing:** run on your laptop (free).
- **Production:** run on an always-on host (Render / Fly.io / VPS, ~$5-7/month).

## Structure

```
src/
  index.js      entry point (MQTT wiring, heartbeat, graceful shutdown)
  config.js     env var loading + validation (multi-topic, tuning knobs)
  parser.js     DRGT message parsing (JPCONFIG / JPUPDATE)
  forwarder.js  sends normalized JSON to the Worker (retry + timeout)
  logger.js     structured single-line JSON logging
test/
  parser.test.js
  forwarder.test.js
```

Features: auto-reconnect, per-message forward retry, periodic heartbeat log,
optional dead-man's-switch ping (`HEALTHCHECK_URL`), and clean shutdown on
`SIGINT`/`SIGTERM` (host stop / redeploy).

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
npm start     # start the listener
npm test      # run parser tests (no network needed)
```

You should see:

```json
{"level":"info","ts":"...","message":"mqtt connected","broker":"mqtts://...:8883"}
{"level":"info","ts":"...","message":"mqtt subscribed","topics":["/jp/gent"]}
{"level":"info","ts":"...","message":"message received","topic":"/jp/gent","raw":"JPUPDATE;O136;2;0;217;53467;867;0;IFCO"}
{"level":"info","ts":"...","message":"forwarded to worker","status":200,"jpId":"O136","attempts":1}
```

## Deploy to Render (production)

1. Push this repo to GitHub.
2. Render -> New -> **Background Worker** (not a Web Service).
3. Root directory: `mqtt-listener`
4. Build command: `npm install`
5. Start command: `npm start`
6. Instance type: **Starter** (always-on, no cold start).
7. Add the same environment variables from your `.env`.
