/**
 * Security helpers: constant-time compare + HMAC-SHA256 signing.
 * Compatible with Node.js 14+.
 */

'use strict';

const crypto = require('crypto');

/**
 * @param {string} a
 * @param {string} b
 * @returns {boolean}
 */
function timingSafeEqualString(a, b) {
  if (typeof a !== 'string' || typeof b !== 'string') return false;
  const bufA = Buffer.from(a);
  const bufB = Buffer.from(b);
  if (bufA.length !== bufB.length) {
    const dummy = Buffer.alloc(bufA.length);
    crypto.timingSafeEqual(bufA, dummy);
    return false;
  }
  return crypto.timingSafeEqual(bufA, bufB);
}

/**
 * Compute a lowercase hex HMAC-SHA256 of `message` using `secret`.
 * Matches WordPress hash_hmac('sha256', $body, $secret).
 *
 * @param {string} secret
 * @param {string} message
 * @returns {string}
 */
function hmacSha256Hex(secret, message) {
  return crypto.createHmac('sha256', String(secret)).update(String(message), 'utf8').digest('hex');
}

module.exports = {
  timingSafeEqualString: timingSafeEqualString,
  hmacSha256Hex: hmacSha256Hex,
};
