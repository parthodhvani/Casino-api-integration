/**
 * Jackpot Worker (middleware / fan-out)
 *
 * Receives a normalized JSON payload from the MQTT listener, signs it with
 * HMAC-SHA256, and forwards it to every configured WordPress site.
 *
 * Environment:
 *   Secrets (wrangler secret put ...):
 *     LISTENER_SECRET  - shared secret that only the MQTT listener knows
 *     JACKPOT_SECRET   - default HMAC secret (used when a site has no own secret)
 *
 *   Vars (wrangler.toml [vars]):
 *     WP_SITES - JSON array of sites, e.g.
 *        [
 *          { "name": "berck",   "url": "https://.../wp-json/jackpot/v1/update" },
 *          { "name": "oostende","url": "https://.../wp-json/jackpot/v1/update", "secret": "per-site-secret" }
 *        ]
 *     If a site has its own "secret", it is used for that site's signature;
 *     otherwise JACKPOT_SECRET is used. This lets all 3 sites share one secret
 *     (simple) or use different secrets (stronger isolation).
 */

export default {
  async fetch(request, env) {
    if (request.method === 'GET') {
      return json({ ok: true, service: 'jackpot-worker' });
    }

    if (request.method !== 'POST') {
      return json({ error: 'method not allowed' }, 405);
    }

    if (!env.LISTENER_SECRET || request.headers.get('x-listener-secret') !== env.LISTENER_SECRET) {
      return json({ error: 'unauthorized' }, 401);
    }

    const sites = parseSites(env.WP_SITES);
    if (sites.length === 0) {
      return json({ error: 'no WP_SITES configured' }, 500);
    }

    const body = await request.text();

    const results = await Promise.all(
      sites.map(async (site) => {
        const secret = site.secret || env.JACKPOT_SECRET;
        if (!secret) {
          return { site: site.name, error: 'no secret (set JACKPOT_SECRET or site.secret)' };
        }
        try {
          const signature = await hmacSha256Hex(secret, body);
          const res = await fetch(site.url, {
            method: 'POST',
            headers: {
              'content-type': 'application/json',
              'x-signature': signature,
            },
            body,
          });
          const text = await res.text();
          return { site: site.name, url: site.url, status: res.status, body: text.slice(0, 300) };
        } catch (err) {
          return { site: site.name, url: site.url, error: String(err) };
        }
      })
    );

    const anyFailed = results.some((r) => r.error || (r.status && r.status >= 400));
    return json({ ok: !anyFailed, results }, anyFailed ? 207 : 200);
  },
};

function parseSites(raw) {
  if (!raw) return [];
  try {
    const arr = JSON.parse(raw);
    if (!Array.isArray(arr)) return [];
    return arr
      .filter((s) => s && s.url)
      .map((s, i) => ({ name: s.name || `site${i + 1}`, url: s.url, secret: s.secret }));
  } catch {
    return [];
  }
}

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
