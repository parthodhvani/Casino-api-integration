/**
 * Re-exports from the single-file Worker (dashboard pasteable: worker.js).
 * Kept so existing imports/docs that reference src/ still resolve.
 */
export {
  hmacSha256Hex,
  timingSafeEqual,
} from '../worker.js';
