/**
 * MQTT Listener — entry point.
 *
 * Holds a persistent MQTT connection to HiveMQ, parses each DRGT message,
 * and forwards a normalized JSON payload to the Cloudflare Worker.
 *
 * Testing:    run on your laptop (`npm start`).
 * Production: run on an always-on host (Render/Fly/VPS) with the same env vars.
 */

const mqtt = require('mqtt');
const config = require('./config');
const { parseMessage } = require('./parser');
const { forwardToWorker } = require('./forwarder');

const client = mqtt.connect(config.mqtt.brokerUrl, {
  username: config.mqtt.username,
  password: config.mqtt.password,
  reconnectPeriod: 5000, // auto-reconnect every 5s on drop
  connectTimeout: 30000,
});

client.on('connect', () => {
  console.log(`[mqtt] connected to ${config.mqtt.brokerUrl}`);
  client.subscribe(config.mqtt.topic, (err) => {
    if (err) {
      console.error('[mqtt] subscribe error:', err.message);
    } else {
      console.log(`[mqtt] subscribed to ${config.mqtt.topic}`);
    }
  });
});

client.on('reconnect', () => console.log('[mqtt] reconnecting...'));
client.on('close', () => console.log('[mqtt] connection closed'));
client.on('error', (err) => console.error('[mqtt] error:', err.message));

client.on('message', async (topic, message) => {
  const raw = message.toString();
  console.log(`[msg] ${topic} => ${raw}`);

  const payload = parseMessage(raw);
  if (!payload) {
    console.log('[msg] skipped (unknown or malformed)');
    return;
  }

  await forwardToWorker(payload, config.worker);
});

process.on('SIGINT', () => {
  console.log('\n[shutdown] closing MQTT connection...');
  client.end(true, () => process.exit(0));
});
