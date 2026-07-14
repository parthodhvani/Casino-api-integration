/**
 * Re-exports the single-file Worker entry (dashboard pasteable: worker.js).
 * Prefer importing / deploying worker.js directly.
 */
export { default } from '../worker.js';
export {
  hmacSha256Hex,
  timingSafeEqual,
  parseSites,
  validateMessage,
  parseBody,
  forwardToSite,
  forwardControl,
} from '../worker.js';
