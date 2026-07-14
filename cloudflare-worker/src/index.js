/**
 * Jackpot Worker (middleware / fan-out)
 *
 * Pipeline:  MQTT listener  ->  [this Worker]  ->  WordPress REST (xN sites)
 *
 * Responsibilities:
 *   1. Authenticate the caller via a constant-time secret check.
 *   2. Validate the payload (single message or a batch array) for fan-out.
 *   3. Sign each message per-site with HMAC-SHA256 and fan out with retry.
 *   4. Proxy MQTT control (start / stop / status) to the Node listener.
 *   5. Return structured, aggregated results + metrics.
 *
 * Environment:
 *   Secrets (wrangler secret put ...):
 *     LISTENER_SECRET  - shared secret only the MQTT listener / control callers know
 *     JACKPOT_SECRET   - default HMAC secret (used when a site has no own secret)
 *   Vars (wrangler.toml [vars]):
 *     WP_SITES              - JSON array of { name, url, secret? }
 *     LISTENER_CONTROL_URL  - base URL of the Node control API (e.g. https://listener:3099)
 */

import { timingSafeEqual, hmacSha256Hex } from './hmac.js';
import { parseSites } from './sites.js';
import { parseBody, validateMessage } from './validator.js';
import { forwardToSite } from './forwarder.js';
import { forwardControl } from './control.js';

export default {
  async fetch(request, env) {
    const started = Date.now();
    const url = new URL(request.url);
    const path = url.pathname.replace(/\/+$/, '') || '/';
    const method = request.method.toUpperCase();

    // --- Health (open) -------------------------------------------------
    if (method === 'GET' && path === '/') {
      return json({ ok: true, service: 'jackpot-worker', version: '3.1.0' });
    }

    // --- MQTT control proxies ------------------------------------------
    if (path === '/start' || path === '/stop' || path === '/status') {
      const auth = await authorizeControl(request, env);
      if (!auth.ok) {
        log('warn', 'control unauthorized', { path, reason: auth.reason });
        return json({ error: 'unauthorized' }, 401);
      }

      if (path === '/status' && method === 'GET') {
        return forwardControl('status', env);
      }
      if (path === '/start' && method === 'POST') {
        return forwardControl('start', env);
      }
      if (path === '/stop' && method === 'POST') {
        return forwardControl('stop', env);
      }
      return json({ error: 'method not allowed' }, 405);
    }

    // --- Existing fan-out path (POST /) --------------------------------
    if (method !== 'POST') {
      return json({ error: 'method not allowed' }, 405);
    }

    // 1. Authenticate the listener (constant-time).
    const provided = request.headers.get('x-listener-secret') || '';
    if (!env.LISTENER_SECRET || !timingSafeEqual(provided, env.LISTENER_SECRET)) {
      log('warn', 'unauthorized request', { hasSecret: Boolean(env.LISTENER_SECRET) });
      return json({ error: 'unauthorized' }, 401);
    }

    // 2. Configuration checks.
    const sites = parseSites(env.WP_SITES);
    if (sites.length === 0) {
      log('error', 'no WP_SITES configured');
      return json({ error: 'no WP_SITES configured' }, 500);
    }

    // 3. Parse + validate the body (supports single object or batch array).
    const bodyText = await request.text();
    const parsed = parseBody(bodyText);
    if (!parsed.ok) {
      return json({ error: parsed.error }, 400);
    }

    const validMessages = [];
    const rejected = [];
    for (const msg of parsed.messages) {
      const check = validateMessage(msg);
      if (check.valid) {
        validMessages.push(msg);
      } else {
        rejected.push({ error: check.error, jpId: msg && msg.jpId });
      }
    }

    if (validMessages.length === 0) {
      return json({ ok: false, error: 'no valid messages', rejected }, 400);
    }

    // 4. Fan out: for each valid message, sign + POST to each site.
    const forwardOpts = {
      retries: numberFrom(env.FORWARD_RETRIES, 2),
      timeoutMs: numberFrom(env.FORWARD_TIMEOUT_MS, 8000),
    };

    const results = [];
    await Promise.all(
      validMessages.map(async (msg) => {
        const body = JSON.stringify(msg);
        const perSite = await Promise.all(
          sites.map((site) => forwardToSite(site, body, env.JACKPOT_SECRET, forwardOpts))
        );
        results.push({ jpId: msg.jpId, type: msg.type, sites: perSite });
      })
    );

    const anyFailed =
      rejected.length > 0 || results.some((r) => r.sites.some((s) => !s.ok));

    const metrics = {
      messages: validMessages.length,
      rejected: rejected.length,
      sites: sites.length,
      durationMs: Date.now() - started,
    };

    log(anyFailed ? 'warn' : 'info', 'fan-out complete', metrics);

    return json({ ok: !anyFailed, metrics, results, rejected }, anyFailed ? 207 : 200);
  },
};

/**
 * Authorize a control request.
 *
 * Accepts either:
 *   - x-listener-secret matching LISTENER_SECRET (cron / listener / admin), or
 *   - X-Signature HMAC-SHA256(JACKPOT_SECRET, raw body) for WordPress AJAX.
 *
 * @param {Request} request
 * @param {object} env
 * @returns {Promise<{ok:boolean, reason?:string}>}
 */
async function authorizeControl(request, env) {
  const listenerSecret = request.headers.get('x-listener-secret') || '';
  if (env.LISTENER_SECRET && timingSafeEqual(listenerSecret, env.LISTENER_SECRET)) {
    return { ok: true };
  }

  // HMAC path used by WordPress (reuses the existing JACKPOT_SECRET).
  const signature = request.headers.get('x-signature') || '';
  if (env.JACKPOT_SECRET && signature) {
    // Clone-safe: read body text for POST; GET /status signs an empty string.
    let bodyText = '';
    if (request.method.toUpperCase() !== 'GET') {
      bodyText = await request.clone().text();
    }
    const expected = await hmacSha256Hex(env.JACKPOT_SECRET, bodyText);
    if (timingSafeEqual(signature, expected)) {
      return { ok: true };
    }
    return { ok: false, reason: 'hmac_mismatch' };
  }

  return { ok: false, reason: 'missing_credentials' };
}

/**
 * @param {object} obj
 * @param {number} status
 * @returns {Response}
 */
function json(obj, status = 200) {
  return new Response(JSON.stringify(obj), {
    status,
    headers: { 'content-type': 'application/json' },
  });
}

/**
 * Structured JSON log line (visible in `wrangler tail`).
 *
 * @param {'info'|'warn'|'error'} level
 * @param {string} message
 * @param {object} [context]
 */
function log(level, message, context = {}) {
  const line = { level, ts: new Date().toISOString(), message, ...context };
  const method = level === 'error' ? 'error' : level === 'warn' ? 'warn' : 'log';
  console[method](JSON.stringify(line));
}

/**
 * @param {string|undefined} value
 * @param {number} fallback
 * @returns {number}
 */
function numberFrom(value, fallback) {
  const n = Number(value);
  return Number.isFinite(n) && n >= 0 ? n : fallback;
}
