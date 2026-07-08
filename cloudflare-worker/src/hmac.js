/**
 * HMAC helpers (Web Crypto — works in Cloudflare Workers and Node 18+).
 *
 * The signature MUST be computed over the exact bytes that are sent as the
 * request body so WordPress can recompute an identical HMAC.
 */

/**
 * Compute a lowercase hex HMAC-SHA256 of `message` using `secret`.
 *
 * @param {string} secret
 * @param {string} message
 * @returns {Promise<string>} 64-char hex string
 */
export async function hmacSha256Hex(secret, message) {
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
 * Constant-time string comparison to avoid timing attacks when checking
 * shared secrets. Compares every character regardless of early mismatches.
 *
 * @param {string} a
 * @param {string} b
 * @returns {boolean}
 */
export function timingSafeEqual(a, b) {
  const sa = String(a);
  const sb = String(b);
  // Length is not secret; bail fast but still on a fixed-ish path.
  if (sa.length !== sb.length) {
    return false;
  }
  let diff = 0;
  for (let i = 0; i < sa.length; i++) {
    diff |= sa.charCodeAt(i) ^ sb.charCodeAt(i);
  }
  return diff === 0;
}
