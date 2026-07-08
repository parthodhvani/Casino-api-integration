# MQTT Listener

Holds the persistent connection to HiveMQ, parses DRGT messages, and forwards
normalized JSON to the Cloudflare Worker.

- **Testing:** run on your laptop (free).
- **Production:** run on an always-on host (Render / Fly.io / VPS, ~$5-7/month).

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
npm start
```

You should see:

```
[mqtt] connected to mqtts://...hivemq.cloud:8883
[mqtt] subscribed to /jp/gent
[msg] /jp/gent => JPUPDATE;O136;2;0;217;53467;867;0;IFCO
[worker] 200 {"ok":true,...}
```

## Deploy to Render (production)

1. Push this repo to GitHub.
2. Render -> New -> **Background Worker** (not a Web Service).
3. Root directory: `mqtt-listener`
4. Build command: `npm install`
5. Start command: `npm start`
6. Instance type: **Starter** (always-on, no cold start).
7. Add the same environment variables from your `.env`.
