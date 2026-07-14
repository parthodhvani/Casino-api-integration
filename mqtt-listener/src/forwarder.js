/**
 * Forwards a normalized payload to the Cloudflare Worker with timeout + retry.
 * Compatible with Node.js 14+ (uses node-fetch + abort-controller).
 */

'use strict';

const logger = require('./logger');
const http = require('./http');

function sleep(ms) {
  return new Promise(function (resolve) {
    setTimeout(resolve, ms);
  });
}

/**
 * Forward a payload to the Worker, retrying transient failures.
 *
 * @param {object} payload normalized message
 * @param {object} workerConfig { url, listenerSecret, retries, timeoutMs, backoffMs }
 * @returns {Promise<boolean>} true when delivered (2xx)
 */
async function forwardToWorker(payload, workerConfig) {
  const retries = workerConfig.retries != null ? workerConfig.retries : 3;
  const timeoutMs = workerConfig.timeoutMs != null ? workerConfig.timeoutMs : 8000;
  const backoffMs = workerConfig.backoffMs != null ? workerConfig.backoffMs : 500;
  const body = JSON.stringify(payload);

  for (let attempt = 0; attempt <= retries; attempt++) {
    try {
      const res = await http.fetchWithTimeout(
        workerConfig.url,
        {
          method: 'POST',
          headers: {
            'content-type': 'application/json',
            'x-listener-secret': workerConfig.listenerSecret,
          },
          body: body,
        },
        timeoutMs
      );
      const text = await res.text();

      if (res.ok) {
        logger.info('forwarded to worker', {
          status: res.status,
          jpId: payload.jpId,
          attempts: attempt + 1,
        });
        return true;
      }

      // 4xx is definitive (bad payload / auth) — do not retry.
      if (res.status >= 400 && res.status < 500) {
        logger.error('worker rejected payload', {
          status: res.status,
          body: text.slice(0, 200),
          jpId: payload.jpId,
        });
        return false;
      }

      logger.warn('worker error, will retry', { status: res.status, attempt: attempt + 1 });
    } catch (err) {
      logger.warn('forward attempt failed', {
        error: err && err.message ? err.message : String(err),
        attempt: attempt + 1,
      });
    }

    if (attempt < retries) {
      await sleep(backoffMs * Math.pow(2, attempt));
    }
  }

  logger.error('forward failed after retries', { jpId: payload.jpId, retries: retries });
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
    await http.fetchWithTimeout(url, { method: 'GET' }, 5000);
  } catch (err) {
    logger.warn('healthcheck ping failed', {
      error: err && err.message ? err.message : String(err),
    });
  }
}

module.exports = { forwardToWorker: forwardToWorker, pingHealthcheck: pingHealthcheck };
