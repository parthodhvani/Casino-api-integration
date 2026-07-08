# Testing

## Automated tests

### WordPress plugin (PHP)

A self-contained functional suite runs the full create/update/skip/validate/
format/image/retention pipeline against in-memory WordPress stubs — **no WP
install required**.

```bash
php wordpress-plugin/tests/run-tests.php
# -> Passed: 48, Failed: 0
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

Lint every PHP file:

```bash
find wordpress-plugin/jackpot-sync -name '*.php' -exec php -l {} \;
```

### Cloudflare Worker (Node)

```bash
cd cloudflare-worker && npm install && npm test
# -> 11 pass
```

Covers HMAC (incl. a known vector), constant-time compare, `WP_SITES` parsing,
message validation, body parsing (single + batch), and forwarder retry/timeout
behavior (5xx retried, 4xx not, no-secret error).

Build check:

```bash
cd cloudflare-worker && npx wrangler deploy --dry-run
```

### MQTT Listener (Node)

```bash
cd mqtt-listener && npm install && npm test
# -> 10 pass
```

Covers parser (incl. whitespace + missing-field rejection) and forwarder
retry/timeout behavior.

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

### 4. Full pipeline checklist

1. Listener terminal → `message received` then `forwarded to worker`.
2. Worker logs (`npm run tail`) → incoming POST + per-site results.
3. WordPress → `wp-admin/edit.php?post_type=jackpot`: JPCONFIG creates a post,
   JPUPDATE changes the two ACF values.
4. Front-end carousel updates after cache purge.

### Euros vs cents

Compare one live `jpValue` against the displayed jackpot:
`€534.67` from `53467` → choose **Cents**; `€53,467` → choose **Whole units**.
