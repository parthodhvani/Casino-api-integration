/**
 * Parses the WP_SITES configuration (a JSON array string) into a normalized
 * list of site descriptors. Invalid entries are dropped rather than throwing.
 */

/**
 * @typedef {Object} Site
 * @property {string} name
 * @property {string} url
 * @property {string} [secret]
 */

/**
 * @param {string|undefined} raw JSON array string from env.WP_SITES
 * @returns {Site[]}
 */
export function parseSites(raw) {
  if (!raw) return [];
  let arr;
  try {
    arr = JSON.parse(raw);
  } catch {
    return [];
  }
  if (!Array.isArray(arr)) return [];
  return arr
    .filter((s) => s && typeof s.url === 'string' && s.url)
    .map((s, i) => ({
      name: s.name || `site${i + 1}`,
      url: s.url,
      secret: typeof s.secret === 'string' && s.secret ? s.secret : undefined,
    }));
}
