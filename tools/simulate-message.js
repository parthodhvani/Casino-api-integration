/**
 * Test tool: send a fake jackpot message without waiting for real MQTT.
 *
 * Two modes:
 *
 *   1. Via the Worker (default) — exercises Worker -> WordPress:
 *        WORKER_URL=... LISTENER_SECRET=... node tools/simulate-message.js config
 *        WORKER_URL=... LISTENER_SECRET=... node tools/simulate-message.js update
 *
 *   2. Direct to WordPress (--direct) — signs the body itself and POSTs straight
 *      to a site, bypassing the Worker (useful for isolating WP issues):
 *        WP_URL=https://site/wp-json/jackpot/v1/update JACKPOT_SECRET=... \
 *          node tools/simulate-message.js update --direct
 *
 * Requires Node.js 18+ (built-in fetch + crypto).
 */

const crypto = require('crypto');

const kind = (process.argv[2] || 'update').toLowerCase();
const direct = process.argv.includes('--direct');

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

async function viaWorker() {
  const { WORKER_URL, LISTENER_SECRET } = process.env;
  if (!WORKER_URL || !LISTENER_SECRET) {
    console.error('Set WORKER_URL and LISTENER_SECRET env vars first.');
    process.exit(1);
  }
  console.log(`Sending ${payload.type} to Worker ${WORKER_URL}`);
  const res = await fetch(WORKER_URL, {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'x-listener-secret': LISTENER_SECRET },
    body: JSON.stringify(payload),
  });
  console.log(`HTTP ${res.status}`);
  console.log(await res.text());
}

async function directToWordPress() {
  const { WP_URL, JACKPOT_SECRET } = process.env;
  if (!WP_URL || !JACKPOT_SECRET) {
    console.error('For --direct set WP_URL and JACKPOT_SECRET env vars.');
    process.exit(1);
  }
  const body = JSON.stringify(payload);
  const signature = crypto.createHmac('sha256', JACKPOT_SECRET).update(body).digest('hex');
  console.log(`Sending ${payload.type} directly to WordPress ${WP_URL}`);
  const res = await fetch(WP_URL, {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'x-signature': signature },
    body,
  });
  console.log(`HTTP ${res.status}`);
  console.log(await res.text());
}

(async () => {
  try {
    if (direct) {
      await directToWordPress();
    } else {
      await viaWorker();
    }
  } catch (err) {
    console.error('Request failed:', err.message);
    process.exit(1);
  }
})();
