/**
 * Jackpot Worker (middleware / fan-out)
 *
 * Receives a normalized JSON payload from the MQTT listener, signs it with
 * HMAC-SHA256, and forwards it to every configured WordPress endpoint.
 *
 * Environment:
 *   Secrets (wrangler secret put ...):
 *     JACKPOT_SECRET   - shared HMAC secret, identical to the WordPress side
 *     LISTENER_SECRET  - shared secret that only the MQTT listener knows
 *   Vars (wrangler.toml [vars]):
 *     WP_ENDPOINTS     - comma-separated list of WordPress endpoint URLs
 */

export default {
  async fetch(request, env, ctx) {
    if (request.method === 'GET') {
      return json({ ok: true, service: 'jackpot-worker' });
    }

    if (request.method !== 'POST') {
      return json({ error: 'method not allowed' }, 405);
    }

    // Authenticate the caller (the MQTT listener).
    if (!env.LISTENER_SECRET || request.headers.get('x-listener-secret') !== env.LISTENER_SECRET) {
      return json({ error: 'unauthorized' }, 401);
    }

    if (!env.JACKPOT_SECRET) {
      return json({ error: 'server not configured: JACKPOT_SECRET missing' }, 500);
    }

    const body = await request.text();

    // Sign the exact body bytes we forward.
    const signature = await hmacSha256Hex(env.JACKPOT_SECRET, body);

    const endpoints = (env.WP_ENDPOINTS || '')
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean);

    if (endpoints.length === 0) {
      return json({ error: 'no WP_ENDPOINTS configured' }, 500);
    }

    // Fan out to all sites in parallel. One site failing must not block others.
    const results = await Promise.all(
      endpoints.map(async (url) => {
        try {
          const res = await fetch(url, {
            method: 'POST',
            headers: {
              'content-type': 'application/json',
              'x-signature': signature,
            },
            body,
          });
          const text = await res.text();
          return { url, status: res.status, body: text.slice(0, 300) };
        } catch (err) {
          return { url, error: String(err) };
        }
      })
    );

    const anyFailed = results.some((r) => r.error || (r.status && r.status >= 400));
    return json({ ok: !anyFailed, results }, anyFailed ? 207 : 200);
  },
};

function json(obj, status = 200) {
  return new Response(JSON.stringify(obj), {
    status,
    headers: { 'content-type': 'application/json' },
  });
}

async function hmacSha256Hex(secret, message) {
  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey(
    'raw',
    enc.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  );
  const sig = await crypto.subtle.sign('HMAC', key, enc.encode(message));
  return [...new Uint8Array(sig)].map((b) => b.toString(16).padStart(2, '0')).join('');
}
