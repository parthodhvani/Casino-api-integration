/**
 * Forwards a normalized payload to the Cloudflare Worker.
 * Requires Node.js 18+ (built-in fetch).
 */

async function forwardToWorker(payload, workerConfig) {
  try {
    const res = await fetch(workerConfig.url, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'x-listener-secret': workerConfig.listenerSecret,
      },
      body: JSON.stringify(payload),
    });
    const text = await res.text();
    console.log(`[worker] ${res.status} ${text.slice(0, 200)}`);
    return res.ok;
  } catch (err) {
    console.error('[worker] forward failed:', err.message);
    return false;
  }
}

module.exports = { forwardToWorker };
