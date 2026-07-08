/**
 * Payload validation shared by the Worker.
 *
 * Mirrors the WordPress-side validation so malformed packets are rejected at
 * the edge before any request is forwarded. Never throws.
 */

/**
 * Validate a single normalized message object.
 *
 * @param {any} msg
 * @returns {{ valid: boolean, error?: string }}
 */
export function validateMessage(msg) {
  if (!msg || typeof msg !== 'object' || Array.isArray(msg)) {
    return { valid: false, error: 'message must be an object' };
  }

  const type = typeof msg.type === 'string' ? msg.type.toUpperCase() : '';
  if (type !== 'JPCONFIG' && type !== 'JPUPDATE') {
    return { valid: false, error: `unknown type: ${type || '(none)'}` };
  }
  if (!msg.jpId) {
    return { valid: false, error: 'missing jpId' };
  }
  if (!msg.casId) {
    return { valid: false, error: 'missing casId' };
  }

  if (type === 'JPCONFIG') {
    if (!msg.jpName) {
      return { valid: false, error: 'JPCONFIG requires jpName' };
    }
  }

  if (type === 'JPUPDATE') {
    if (msg.jpValue === undefined || msg.jpShared === undefined) {
      return { valid: false, error: 'JPUPDATE requires jpValue and jpShared' };
    }
    if (Number.isNaN(Number(msg.jpValue)) || Number.isNaN(Number(msg.jpShared))) {
      return { valid: false, error: 'jpValue and jpShared must be numeric' };
    }
  }

  return { valid: true };
}

/**
 * Normalize the request body into an array of messages (supports batching).
 *
 * @param {string} bodyText raw request body
 * @returns {{ ok: boolean, messages?: any[], error?: string }}
 */
export function parseBody(bodyText) {
  let data;
  try {
    data = JSON.parse(bodyText);
  } catch {
    return { ok: false, error: 'invalid json' };
  }
  const messages = Array.isArray(data) ? data : [data];
  if (messages.length === 0) {
    return { ok: false, error: 'empty batch' };
  }
  return { ok: true, messages };
}
