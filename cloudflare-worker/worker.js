/**
 * Jackpot Worker — single-file standalone (Cloudflare Dashboard pasteable).
 *
 * Pipeline:  MQTT listener  ->  [this Worker]  ->  WordPress REST (xN sites)
 *
 * Paste this entire file into:
 *   Cloudflare Dashboard → Workers & Pages → Edit Code
 *
 * Secrets: LISTENER_SECRET, JACKPOT_SECRET
 * Vars:    WP_SITES, LISTENER_CONTROL_URL, CONTROL_TIMEOUT_MS,
 *          FORWARD_RETRIES, FORWARD_TIMEOUT_MS
 */

export default {
  async fetch(request, env, ctx) {
    const started = Date.now();
    const url = new URL(request.url);
    const path = url.pathname.replace(/\/+$/, '') || '/';
    const method = request.method.toUpperCase();

    // --- Health (open) -------------------------------------------------
    if (method === 'GET' && path === '/') {
      return json({ ok: true, service: 'jackpot-worker', version: '3.1.1' });
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

    // --- Fan-out path (POST /) -----------------------------------------
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

/* =====================================================================
 * Authentication helpers
 * ===================================================================== */

/**
 * Authorize a control request.
 * Accepts x-listener-secret OR X-Signature HMAC with JACKPOT_SECRET.
 */
async function authorizeControl(request, env) {
  const listenerSecret = request.headers.get('x-listener-secret') || '';
  if (env.LISTENER_SECRET && timingSafeEqual(listenerSecret, env.LISTENER_SECRET)) {
    return { ok: true };
  }

  const signature = request.headers.get('x-signature') || '';
  if (env.JACKPOT_SECRET && signature) {
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

/* =====================================================================
 * HMAC helpers
 * ===================================================================== */

/**
 * Compute a lowercase hex HMAC-SHA256 of `message` using `secret`.
 */
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

/**
 * Constant-time string comparison to avoid timing attacks.
 */
function timingSafeEqual(a, b) {
  const sa = String(a);
  const sb = String(b);
  if (sa.length !== sb.length) {
    return false;
  }
  let diff = 0;
  for (let i = 0; i < sa.length; i++) {
    diff |= sa.charCodeAt(i) ^ sb.charCodeAt(i);
  }
  return diff === 0;
}

/* =====================================================================
 * Site parser
 * ===================================================================== */

/**
 * Parse WP_SITES JSON into normalized site descriptors.
 */
function parseSites(raw) {
  if (!raw) return [];
  let arr;
  try {
    arr = JSON.parse(raw);
  } catch {
    return [];
  }
  if (!Array.isArray(arr)) return [];
  return arr
    .filter((s) => s && typeof s.url === 'string' && s.url)
    .map((s, i) => ({
      name: s.name || `site${i + 1}`,
      url: s.url,
      secret: typeof s.secret === 'string' && s.secret ? s.secret : undefined,
    }));
}

/* =====================================================================
 * Validation helpers
 * ===================================================================== */

/**
 * Validate a single normalized message object.
 */
function validateMessage(msg) {
  if (!msg || typeof msg !== 'object' || Array.isArray(msg)) {
    return { valid: false, error: 'message must be an object' };
  }

  const type = typeof msg.type === 'string' ? msg.type.toUpperCase() : '';
  if (type !== 'JPCONFIG' && type !== 'JPUPDATE') {
    return { valid: false, error: `unknown type: ${type || '(none)'}` };
  }
  if (!msg.jpId) {
    return { valid: false, error: 'missing jpId' };
  }
  if (!msg.casId) {
    return { valid: false, error: 'missing casId' };
  }

  if (type === 'JPCONFIG') {
    if (!msg.jpName) {
      return { valid: false, error: 'JPCONFIG requires jpName' };
    }
  }

  if (type === 'JPUPDATE') {
    if (msg.jpValue === undefined || msg.jpShared === undefined) {
      return { valid: false, error: 'JPUPDATE requires jpValue and jpShared' };
    }
    if (Number.isNaN(Number(msg.jpValue)) || Number.isNaN(Number(msg.jpShared))) {
      return { valid: false, error: 'jpValue and jpShared must be numeric' };
    }
  }

  return { valid: true };
}

/**
 * Normalize the request body into an array of messages (supports batching).
 */
function parseBody(bodyText) {
  let data;
  try {
    data = JSON.parse(bodyText);
  } catch {
    return { ok: false, error: 'invalid json' };
  }
  const messages = Array.isArray(data) ? data : [data];
  if (messages.length === 0) {
    return { ok: false, error: 'empty batch' };
  }
  return { ok: true, messages };
}

/* =====================================================================
 * Forwarding helpers (WordPress)
 * ===================================================================== */

/**
 * POST a body to a URL with an AbortController timeout.
 */
async function fetchWithTimeout(url, options, timeoutMs) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(url, { ...options, signal: controller.signal });
  } finally {
    clearTimeout(timer);
  }
}

/**
 * Forward a single message body to a single site, retrying on failure.
 */
async function forwardToSite(site, body, defaultSecret, opts = {}) {
  const retries = opts.retries ?? 2;
  const timeoutMs = opts.timeoutMs ?? 8000;
  const backoffMs = opts.backoffMs ?? 300;

  const secret = site.secret || defaultSecret;
  if (!secret) {
    return { site: site.name, url: site.url, ok: false, error: 'no secret configured' };
  }

  const signature = await hmacSha256Hex(secret, body);
  let lastError = null;

  for (let attempt = 0; attempt <= retries; attempt++) {
    try {
      const res = await fetchWithTimeout(
        site.url,
        {
          method: 'POST',
          headers: { 'content-type': 'application/json', 'x-signature': signature },
          body,
        },
        timeoutMs
      );
      const text = await res.text();
      if (res.status >= 500 && attempt < retries) {
        lastError = `HTTP ${res.status}`;
        await sleep(backoffMs * Math.pow(2, attempt));
        continue;
      }
      return {
        site: site.name,
        url: site.url,
        ok: res.status < 400,
        status: res.status,
        attempts: attempt + 1,
        body: text.slice(0, 300),
      };
    } catch (err) {
      lastError = String(err && err.message ? err.message : err);
      if (attempt < retries) {
        await sleep(backoffMs * Math.pow(2, attempt));
      }
    }
  }

  return { site: site.name, url: site.url, ok: false, error: lastError, attempts: retries + 1 };
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/* =====================================================================
 * Control helpers (proxy to Node MQTT listener)
 * ===================================================================== */

/**
 * Forward a control action to the listener HTTP API.
 */
async function forwardControl(action, env) {
  const base = (env.LISTENER_CONTROL_URL || '').replace(/\/+$/, '');
  if (!base) {
    return json(
      {
        error: 'LISTENER_CONTROL_URL not configured',
        hint: 'In Cloudflare Dashboard → Worker → Settings → Variables, add LISTENER_CONTROL_URL = public base URL of the Node control API (e.g. https://your-server.example.com:3099). Do NOT use http://127.0.0.1 — Workers cannot reach your localhost.',
      },
      500
    );
  }

  if (/^https?:\/\/(localhost|127\.0\.0\.1|0\.0\.0\.0)(:|\/|$)/i.test(base)) {
    return json(
      {
        error: 'LISTENER_CONTROL_URL is not reachable from Cloudflare',
        hint: 'Replace localhost/127.0.0.1 with a public hostname that points to your Node listener control port.',
        LISTENER_CONTROL_URL: base,
      },
      500
    );
  }

  if (!env.LISTENER_SECRET) {
    return json({ error: 'LISTENER_SECRET not configured' }, 500);
  }

  const timeoutMs = numberFrom(env.CONTROL_TIMEOUT_MS, 8000);
  const path = action === 'status' ? '/status' : `/${action}`;
  const method = action === 'status' ? 'GET' : 'POST';
  const targetUrl = `${base}${path}`;

  try {
    const res = await fetchWithTimeout(
      targetUrl,
      {
        method,
        headers: {
          'content-type': 'application/json',
          'x-listener-secret': env.LISTENER_SECRET,
          accept: 'application/json',
        },
      },
      timeoutMs
    );

    const text = await res.text();
    let body;
    try {
      body = JSON.parse(text);
    } catch {
      const snippet = String(text || '')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 200);
      const looksHtml = /<\s*!doctype|<\s*html/i.test(text || '');
      return json(
        {
          error: 'invalid response from listener',
          status: res.status,
          contentType: res.headers.get('content-type') || '',
          body: snippet,
          hint: looksHtml
            ? 'Listener URL returned HTML (login page, proxy error, or wrong path). LISTENER_CONTROL_URL must hit the Node control API directly (GET /status returns JSON).'
            : 'Listener did not return JSON. Check LISTENER_CONTROL_URL points at the Node control server (port CONTROL_PORT, default 3099).',
          targetUrl: targetUrl,
        },
        502
      );
    }

    return json(body, res.status);
  } catch (err) {
    const message = String(err && err.message ? err.message : err);
    const isTimeout = /abort/i.test(message);
    return json(
      {
        error: isTimeout ? 'listener timeout' : 'listener unavailable',
        message: message,
        hint: 'Cloudflare must reach your Node control port over the public internet. Open firewall for CONTROL_PORT and use https://your-public-host:3099 (or a reverse-proxy path).',
        targetUrl: targetUrl,
      },
      isTimeout ? 504 : 502
    );
  }
}

/* =====================================================================
 * Utility functions
 * ===================================================================== */

function json(obj, status = 200) {
  return new Response(JSON.stringify(obj), {
    status,
    headers: { 'content-type': 'application/json' },
  });
}

function log(level, message, context = {}) {
  const line = { level, ts: new Date().toISOString(), message, ...context };
  const method = level === 'error' ? 'error' : level === 'warn' ? 'warn' : 'log';
  console[method](JSON.stringify(line));
}

function numberFrom(value, fallback) {
  const n = Number(value);
  return Number.isFinite(n) && n >= 0 ? n : fallback;
}

/* Named exports for unit tests (ignored by Cloudflare Dashboard runtime). */
export {
  hmacSha256Hex,
  timingSafeEqual,
  parseSites,
  validateMessage,
  parseBody,
  forwardToSite,
  forwardControl,
};
