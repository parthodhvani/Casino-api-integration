/**
 * Test tool: send a fake jackpot message to the Cloudflare Worker,
 * WITHOUT waiting for a real MQTT message.
 *
 * This exercises the Worker -> WordPress path (signature, ACF write, cache purge).
 * Requires Node.js 18+.
 *
 * Usage:
 *   WORKER_URL=... LISTENER_SECRET=... node tools/simulate-message.js config
 *   WORKER_URL=... LISTENER_SECRET=... node tools/simulate-message.js update
 *
 * The default jpId/casId match the brief's example (O136 / IFCO).
 */

const { WORKER_URL, LISTENER_SECRET } = process.env;

if (!WORKER_URL || !LISTENER_SECRET) {
  console.error('Set WORKER_URL and LISTENER_SECRET env vars first.');
  process.exit(1);
}

const kind = (process.argv[2] || 'update').toLowerCase();

const payloads = {
  config: {
    type: 'JPCONFIG',
    jpId: 'O136',
    level: '2',
    jpType: '11',
    jpName: 'Huff n Puff Mystery LVL2',
    prizeName: '',
    casId: 'IFCO',
  },
  update: {
    type: 'JPUPDATE',
    jpId: 'O136',
    level: '2',
    jpValue: '53467',
    jpShared: '867',
    casId: 'IFCO',
  },
};

const payload = payloads[kind];
if (!payload) {
  console.error(`Unknown kind "${kind}". Use "config" or "update".`);
  process.exit(1);
}

(async () => {
  console.log(`Sending ${payload.type} to ${WORKER_URL}`);
  const res = await fetch(WORKER_URL, {
    method: 'POST',
    headers: {
      'content-type': 'application/json',
      'x-listener-secret': LISTENER_SECRET,
    },
    body: JSON.stringify(payload),
  });
  console.log(`HTTP ${res.status}`);
  console.log(await res.text());
})();
