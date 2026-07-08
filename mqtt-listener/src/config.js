/**
 * Loads and validates configuration from environment variables.
 * Works the same on a laptop (via .env) and in production (host env vars).
 */

require('dotenv').config();

function required(name) {
  const value = process.env[name];
  if (!value) {
    console.error(`[fatal] Missing required env var: ${name}`);
    process.exit(1);
  }
  return value;
}

function optionalNumber(name, fallback) {
  const raw = process.env[name];
  if (raw === undefined || raw === '') return fallback;
  const n = Number(raw);
  return Number.isFinite(n) && n >= 0 ? n : fallback;
}

// MQTT_TOPIC may be a single topic or a comma-separated list (future topics).
const topics = required('MQTT_TOPIC')
  .split(',')
  .map((t) => t.trim())
  .filter(Boolean);

const config = {
  mqtt: {
    host: required('MQTT_HOST'),
    port: process.env.MQTT_PORT || '8883',
    username: required('MQTT_USERNAME'),
    password: required('MQTT_PASSWORD'),
    topics,
    reconnectPeriodMs: optionalNumber('MQTT_RECONNECT_MS', 5000),
    connectTimeoutMs: optionalNumber('MQTT_CONNECT_TIMEOUT_MS', 30000),
  },
  worker: {
    url: required('WORKER_URL'),
    listenerSecret: required('LISTENER_SECRET'),
    retries: optionalNumber('WORKER_RETRIES', 3),
    timeoutMs: optionalNumber('WORKER_TIMEOUT_MS', 8000),
    backoffMs: optionalNumber('WORKER_BACKOFF_MS', 500),
  },
  runtime: {
    heartbeatMs: optionalNumber('HEARTBEAT_MS', 60000),
    // Optional dead-man's-switch URL pinged after each successful forward.
    healthcheckUrl: process.env.HEALTHCHECK_URL || '',
  },
};

config.mqtt.brokerUrl = `mqtts://${config.mqtt.host}:${config.mqtt.port}`;

module.exports = config;
