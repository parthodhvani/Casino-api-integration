/**
 * Constant-time string compare for secret headers.
 */

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
    // Still compare to avoid leaking length via timing of an early return alone.
    const dummy = Buffer.alloc(bufA.length);
    require('crypto').timingSafeEqual(bufA, dummy);
    return false;
  }
  return require('crypto').timingSafeEqual(bufA, bufB);
}

module.exports = { timingSafeEqualString };
