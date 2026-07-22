/**
 * Forwards a normalized payload directly to all WordPress sites
 * with HMAC signing, timeout, and exponential-backoff retry.
 * Compatible with Node.js 14+ (uses node-fetch + abort-controller).
 */

'use strict';

const crypto = require('crypto');
const logger = require('./logger');
const http = require('./http');

function hmacSha256Hex(secret, message) {
  return crypto.createHmac('sha256', String(secret)).update(String(message), 'utf8').digest('hex');
}

function sleep(ms) {
  return new Promise(function (resolve) {
    setTimeout(resolve, ms);
  });
}

/**
 * Forward a single JSON body to one WordPress site.
 *
 * @param {{name:string,url:string,secret?:string}} site
 * @param {string} body JSON string (same bytes used for HMAC)
 * @param {string} defaultSecret JACKPOT_SECRET fallback
 * @param {{retries?:number,timeoutMs?:number,backoffMs?:number}} opts
 * @returns {Promise<object>}
 */
async function forwardToSite(site, body, defaultSecret, opts) {
  opts = opts || {};
  const retries = opts.retries != null ? opts.retries : 3;
  const timeoutMs = opts.timeoutMs != null ? opts.timeoutMs : 8000;
  const backoffMs = opts.backoffMs != null ? opts.backoffMs : 500;

  const secret = site.secret || defaultSecret;
  if (!secret) {
    return { site: site.name, url: site.url, ok: false, error: 'no secret configured' };
  }

  const signature = hmacSha256Hex(secret, body);
  let lastError = null;

  for (let attempt = 0; attempt <= retries; attempt++) {
    try {
      const res = await http.fetchWithTimeout(
        site.url,
        {
          method: 'POST',
          headers: {
            'content-type': 'application/json',
            'x-signature': signature,
          },
          body: body,
        },
        timeoutMs
      );
      const text = await res.text();

      if (res.status >= 400 && res.status < 500) {
        logger.error('wordpress rejected payload', {
          site: site.name,
          status: res.status,
          body: text.slice(0, 200),
        });
        return {
          site: site.name,
          url: site.url,
          ok: false,
          status: res.status,
          attempts: attempt + 1,
          body: text.slice(0, 300),
        };
      }

      if (res.status >= 500 && attempt < retries) {
        lastError = 'HTTP ' + res.status;
        logger.warn('wordpress error, will retry', {
          site: site.name,
          status: res.status,
          attempt: attempt + 1,
        });
        await sleep(backoffMs * Math.pow(2, attempt));
        continue;
      }

      if (res.ok || res.status < 400) {
        logger.info('forwarded to wordpress', {
          site: site.name,
          status: res.status,
          attempts: attempt + 1,
        });
        return {
          site: site.name,
          url: site.url,
          ok: true,
          status: res.status,
          attempts: attempt + 1,
          body: text.slice(0, 300),
        };
      }

      lastError = 'HTTP ' + res.status;
    } catch (err) {
      lastError = err && err.message ? err.message : String(err);
      logger.warn('forward attempt failed', {
        site: site.name,
        error: lastError,
        attempt: attempt + 1,
      });
      if (attempt < retries) {
        await sleep(backoffMs * Math.pow(2, attempt));
      }
    }
  }

  logger.error('forward failed after retries', {
    site: site.name,
    error: lastError,
    retries: retries,
  });
  return {
    site: site.name,
    url: site.url,
    ok: false,
    error: lastError,
    attempts: retries + 1,
  };
}

/**
 * Fan-out a normalized payload to every configured WordPress site.
 * Continues to all sites even if one fails.
 *
 * @param {object} payload
 * @param {object} wpConfig
 * @returns {Promise<boolean>}
 */
async function forwardToSites(payload, wpConfig) {
  const sites = wpConfig.sites || [];
  if (sites.length === 0) {
    logger.error('no WP sites configured', { jpId: payload.jpId });
    return false;
  }

  const body = JSON.stringify(payload);
  const opts = {
    retries: wpConfig.retries,
    timeoutMs: wpConfig.timeoutMs,
    backoffMs: wpConfig.backoffMs,
  };

  const results = await Promise.all(
    sites.map(function (site) {
      return forwardToSite(site, body, wpConfig.defaultSecret, opts);
    })
  );

  let okCount = 0;
  for (let i = 0; i < results.length; i++) {
    if (results[i].ok) okCount++;
  }

  logger.info('fan-out complete', {
    jpId: payload.jpId,
    type: payload.type,
    sites: sites.length,
    ok: okCount,
    failed: sites.length - okCount,
  });

  return okCount > 0;
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

module.exports = {
  forwardToSites: forwardToSites,
  forwardToSite: forwardToSite,
  pingHealthcheck: pingHealthcheck,
};
