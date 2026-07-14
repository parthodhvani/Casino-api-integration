/**
 * HTTP helpers compatible with Node.js 14+ (cPanel).
 * Node 14 has no global fetch / AbortController — use polyfills.
 */

'use strict';

const fetch = require('node-fetch');
const AbortController = require('abort-controller');

/**
 * @param {string} url
 * @param {object} options
 * @param {number} timeoutMs
 * @returns {Promise<import('node-fetch').Response>}
 */
async function fetchWithTimeout(url, options, timeoutMs) {
  const controller = new AbortController();
  const timer = setTimeout(function () {
    controller.abort();
  }, timeoutMs);
  try {
    return await fetch(url, Object.assign({}, options, { signal: controller.signal }));
  } finally {
    clearTimeout(timer);
  }
}

module.exports = {
  fetch: fetch,
  AbortController: AbortController,
  fetchWithTimeout: fetchWithTimeout,
};
