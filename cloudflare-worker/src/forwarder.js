/**
 * Forwards a signed message to one WordPress site with timeout + retry.
 */

import { hmacSha256Hex } from './hmac.js';

/**
 * POST a body to a URL with an AbortController timeout.
 *
 * @param {string} url
 * @param {object} options fetch options
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
 * Forward a single message body to a single site, retrying on failure.
 *
 * @param {{name:string,url:string,secret?:string}} site
 * @param {string} body raw JSON to sign + send
 * @param {string} defaultSecret fallback JACKPOT_SECRET
 * @param {{retries?:number, timeoutMs?:number, backoffMs?:number}} [opts]
 * @returns {Promise<object>} per-site result
 */
export async function forwardToSite(site, body, defaultSecret, opts = {}) {
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
      // 5xx is retryable; 4xx is a definitive answer (don't retry).
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

/**
 * @param {number} ms
 * @returns {Promise<void>}
 */
function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
