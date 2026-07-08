/**
 * Forwards a normalized payload to the Cloudflare Worker with timeout + retry.
 * Requires Node.js 18+ (built-in fetch + AbortController).
 */

const logger = require('./logger');

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

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
 * Forward a payload to the Worker, retrying transient failures.
 *
 * @param {object} payload normalized message
 * @param {object} workerConfig { url, listenerSecret, retries, timeoutMs, backoffMs }
 * @returns {Promise<boolean>} true when delivered (2xx)
 */
async function forwardToWorker(payload, workerConfig) {
  const retries = workerConfig.retries ?? 3;
  const timeoutMs = workerConfig.timeoutMs ?? 8000;
  const backoffMs = workerConfig.backoffMs ?? 500;
  const body = JSON.stringify(payload);

  for (let attempt = 0; attempt <= retries; attempt++) {
    try {
      const res = await fetchWithTimeout(
        workerConfig.url,
        {
          method: 'POST',
          headers: {
            'content-type': 'application/json',
            'x-listener-secret': workerConfig.listenerSecret,
          },
          body,
        },
        timeoutMs
      );
      const text = await res.text();

      if (res.ok) {
        logger.info('forwarded to worker', { status: res.status, jpId: payload.jpId, attempts: attempt + 1 });
        return true;
      }

      // 4xx is definitive (bad payload / auth) — do not retry.
      if (res.status >= 400 && res.status < 500) {
        logger.error('worker rejected payload', { status: res.status, body: text.slice(0, 200), jpId: payload.jpId });
        return false;
      }

      logger.warn('worker error, will retry', { status: res.status, attempt: attempt + 1 });
    } catch (err) {
      logger.warn('forward attempt failed', { error: err.message, attempt: attempt + 1 });
    }

    if (attempt < retries) {
      await sleep(backoffMs * Math.pow(2, attempt));
    }
  }

  logger.error('forward failed after retries', { jpId: payload.jpId, retries });
  return false;
}

/**
 * Optional dead-man's-switch ping (best effort, never throws).
 *
 * @param {string} url
 */
async function pingHealthcheck(url) {
  if (!url) return;
  try {
    await fetchWithTimeout(url, { method: 'GET' }, 5000);
  } catch (err) {
    logger.warn('healthcheck ping failed', { error: err.message });
  }
}

module.exports = { forwardToWorker, pingHealthcheck };
