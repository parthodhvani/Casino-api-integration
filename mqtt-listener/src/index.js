/**
 * MQTT Listener — entry point.
 *
 * Holds a persistent MQTT connection to HiveMQ, parses each DRGT message,
 * and forwards a normalized JSON payload to the Cloudflare Worker.
 *
 * Features: auto-reconnect, heartbeat, forward retry, graceful shutdown.
 *
 * Testing:    run on your laptop (`npm start`).
 * Production: run on an always-on host (Render/Fly/VPS) with the same env vars.
 */

const mqtt = require('mqtt');
const config = require('./config');
const logger = require('./logger');
const { parseMessage } = require('./parser');
const { forwardToWorker, pingHealthcheck } = require('./forwarder');

const state = {
  connected: false,
  messages: 0,
  forwarded: 0,
  failed: 0,
  lastMessageAt: null,
};

const client = mqtt.connect(config.mqtt.brokerUrl, {
  username: config.mqtt.username,
  password: config.mqtt.password,
  reconnectPeriod: config.mqtt.reconnectPeriodMs,
  connectTimeout: config.mqtt.connectTimeoutMs,
});

client.on('connect', () => {
  state.connected = true;
  logger.info('mqtt connected', { broker: config.mqtt.brokerUrl });
  client.subscribe(config.mqtt.topics, (err) => {
    if (err) {
      logger.error('mqtt subscribe error', { error: err.message });
    } else {
      logger.info('mqtt subscribed', { topics: config.mqtt.topics });
    }
  });
});

client.on('reconnect', () => logger.warn('mqtt reconnecting'));
client.on('close', () => {
  state.connected = false;
  logger.warn('mqtt connection closed');
});
client.on('offline', () => logger.warn('mqtt offline'));
client.on('error', (err) => logger.error('mqtt error', { error: err.message }));

client.on('message', async (topic, message) => {
  const raw = message.toString();
  state.messages++;
  state.lastMessageAt = new Date().toISOString();
  logger.info('message received', { topic, raw });

  const payload = parseMessage(raw);
  if (!payload) {
    logger.warn('message skipped (unknown or malformed)', { topic, raw });
    return;
  }

  const delivered = await forwardToWorker(payload, config.worker);
  if (delivered) {
    state.forwarded++;
    await pingHealthcheck(config.runtime.healthcheckUrl);
  } else {
    state.failed++;
  }
});

// Heartbeat — periodic status so silent feeds are visible in logs/monitoring.
const heartbeat = setInterval(() => {
  logger.info('heartbeat', {
    connected: state.connected,
    messages: state.messages,
    forwarded: state.forwarded,
    failed: state.failed,
    lastMessageAt: state.lastMessageAt,
  });
}, config.runtime.heartbeatMs);
if (typeof heartbeat.unref === 'function') {
  heartbeat.unref();
}

// Graceful shutdown for both Ctrl-C (SIGINT) and host stop (SIGTERM).
let shuttingDown = false;
function shutdown(signal) {
  if (shuttingDown) return;
  shuttingDown = true;
  logger.info('shutting down', { signal });
  clearInterval(heartbeat);
  // Stop the auto-reconnect loop and close the socket immediately.
  client.removeAllListeners('close');
  client.removeAllListeners('error');
  client.removeAllListeners('offline');
  let done = false;
  const finish = () => {
    if (done) return;
    done = true;
    process.exit(0);
  };
  client.end(true, {}, finish);
  // Safety net: guarantee a prompt, clean exit even if end() never calls back.
  setTimeout(finish, 1500);
}

process.on('SIGINT', () => shutdown('SIGINT'));
process.on('SIGTERM', () => shutdown('SIGTERM'));

module.exports = { client, state };
