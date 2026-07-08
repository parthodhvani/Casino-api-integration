# Security

## Trust boundaries

```
DRGT/MQTT  ──►  Listener  ──►  Worker  ──►  WordPress
             (LISTENER_SECRET)   (JACKPOT_SECRET / X-Signature)
```

Two independent shared secrets protect the two hops that cross the public
internet.

## Hop 1 — Listener → Worker

- The listener sends `x-listener-secret: <LISTENER_SECRET>`.
- The Worker compares it with `timingSafeEqual` (constant-time) and returns
  `401` on mismatch or when the secret is unset.
- This prevents arbitrary callers from using the Worker as an open relay.

## Hop 2 — Worker → WordPress

- The Worker computes `HMAC-SHA256(secret, body)` over the **exact bytes** it
  sends, and adds `X-Signature: <hex>`.
- Each message is signed per-site: a site may have its own `secret` in
  `WP_SITES`, otherwise the shared `JACKPOT_SECRET` is used.
- WordPress recomputes the HMAC over `WP_REST_Request::get_body()` (the raw,
  unmodified body — never a re-encoded version) and compares with
  `hash_equals` (constant-time). Mismatch/missing → request rejected.

### Signature parity

Both sides sign the identical byte string, so HMACs match:

| Side | Code |
|------|------|
| Worker | `crypto.subtle.sign('HMAC'{SHA-256}, key, bytes(body))` |
| WordPress | `hash_hmac('sha256', $request->get_body(), $secret)` |

A test in the plugin suite and a known-vector test in the Worker suite lock this
behavior in.

## Timing attacks

- Listener secret: constant-time compare in the Worker (`timingSafeEqual`).
- WordPress signature: `hash_equals` (constant-time). Never `==`/`!==`.

## Body integrity

- The Worker does **not** mutate the body it signs; it signs `JSON.stringify(msg)`
  and sends that exact string.
- WordPress validates the decoded payload (type, jpId, casId, required fields,
  numeric values) before doing any work, and sanitizes every stored field with
  `sanitize_text_field`.

## Input validation

- Listener parser rejects malformed/unknown messages (returns `null`).
- Worker validates each message and rejects a batch with no valid messages
  (`400`), reporting rejected entries.
- WordPress `Jackpot_Sync_Message_Parser::validate_payload` enforces the schema
  and returns proper REST error codes (`400`, `202`).

## Least privilege / hardening

- Only the signed `/update` route performs writes; `/ping` is read-only.
- Admin tester + settings require the `manage_options` capability and use
  nonces (`check_admin_referer`).
- The signing secret can be locked in `wp-config.php` (`JACKPOT_SECRET`) so it
  is not editable from the dashboard.
- Secrets are generated with `openssl rand -hex 32` and never committed.

## Recommendations

- Rotate the test MQTT credentials before production.
- Put the WordPress endpoint behind the site's normal TLS; ensure the WAF does
  not strip the `X-Signature` header (see [TROUBLESHOOTING.md](TROUBLESHOOTING.md)).
- Use per-site secrets in `WP_SITES` for stronger isolation across sites.
