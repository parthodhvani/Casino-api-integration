# Test tools

Helpers for testing without waiting for live MQTT messages.

## simulate-message.js

Sends a fake `JPCONFIG` or `JPUPDATE` straight to the Worker, so you can test
the **Worker → WordPress** path on demand. Requires Node.js 18+.

```bash
# create the jackpot post first
WORKER_URL="https://jackpot-worker.<sub>.workers.dev" \
LISTENER_SECRET="your-listener-secret" \
node tools/simulate-message.js config

# then push a value update
WORKER_URL="https://jackpot-worker.<sub>.workers.dev" \
LISTENER_SECRET="your-listener-secret" \
node tools/simulate-message.js update
```

Watch the result in `wrangler tail` (Worker) and in wp-admin (the jackpot post).
