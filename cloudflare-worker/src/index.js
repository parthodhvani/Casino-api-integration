/**
 * Jackpot Worker (middleware / fan-out)
 *
 * Pipeline:  MQTT listener  ->  [this Worker]  ->  WordPress REST (xN sites)
 *
 * Responsibilities:
 *   1. Authenticate the caller (listener) via a constant-time secret check.
 *   2. Validate the payload (single message or a batch array).
 *   3. Sign each message per-site with HMAC-SHA256 and fan out with retry.
 *   4. Return structured, aggregated results + metrics.
 *
 * Environment:
 *   Secrets (wrangler secret put ...):
 *     LISTENER_SECRET  - shared secret only the MQTT listener knows
 *     JACKPOT_SECRET   - default HMAC secret (used when a site has no own secret)
 *   Vars (wrangler.toml [vars]):
 *     WP_SITES         - JSON array of { name, url, secret? }
 */

import { timingSafeEqual } from './hmac.js';
import { parseSites } from './sites.js';
import { parseBody, validateMessage } from './validator.js';
import { forwardToSite } from './forwarder.js';

export default {
  async fetch(request, env) {
    const started = Date.now();

    if (request.method === 'GET') {
      return json({ ok: true, service: 'jackpot-worker', version: '3.0.0' });
    }
    if (request.method !== 'POST') {
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
