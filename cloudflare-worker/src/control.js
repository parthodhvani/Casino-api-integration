/**
 * Forwards control requests (start / stop / status) to the Node MQTT listener.
 */

/**
 * @param {string} url
 * @param {object} options
 * @param {number} timeoutMs
 * @returns {Promise<Response>}
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
 * Forward a control action to the listener HTTP API.
 *
 * @param {'start'|'stop'|'status'} action
 * @param {object} env Worker env (LISTENER_CONTROL_URL, LISTENER_SECRET, CONTROL_TIMEOUT_MS)
 * @returns {Promise<Response>}
 */
export async function forwardControl(action, env) {
  const base = (env.LISTENER_CONTROL_URL || '').replace(/\/+$/, '');
  if (!base) {
    return json({ error: 'LISTENER_CONTROL_URL not configured' }, 500);
  }

  if (!env.LISTENER_SECRET) {
    return json({ error: 'LISTENER_SECRET not configured' }, 500);
  }

  const timeoutMs = numberFrom(env.CONTROL_TIMEOUT_MS, 8000);
  const path = action === 'status' ? '/status' : `/${action}`;
  const method = action === 'status' ? 'GET' : 'POST';
  const url = `${base}${path}`;

  try {
    const res = await fetchWithTimeout(
      url,
      {
        method,
        headers: {
          'content-type': 'application/json',
          'x-listener-secret': env.LISTENER_SECRET,
        },
      },
      timeoutMs
    );

    const text = await res.text();
    let body;
    try {
      body = JSON.parse(text);
    } catch {
      return json(
        {
          error: 'invalid response from listener',
          status: res.status,
          body: text.slice(0, 300),
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
        message,
      },
      isTimeout ? 504 : 502
    );
  }
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
 * @param {string|undefined} value
 * @param {number} fallback
 * @returns {number}
 */
function numberFrom(value, fallback) {
  const n = Number(value);
  return Number.isFinite(n) && n >= 0 ? n : fallback;
}
