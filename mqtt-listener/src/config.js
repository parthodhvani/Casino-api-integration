/**
 * Loads and validates configuration from environment variables.
 * Compatible with Node.js 14+.
 */

'use strict';

require('dotenv').config();

function required(name) {
  const value = process.env[name];
  if (!value) {
    console.error('[fatal] Missing required env var: ' + name);
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

function optionalBool(name, fallback) {
  const raw = process.env[name];
  if (raw === undefined || raw === '') return fallback;
  const v = String(raw).toLowerCase();
  return v === '1' || v === 'true' || v === 'yes' || v === 'on';
}

function optionalHHMM(name, fallback) {
  const raw = process.env[name];
  if (!raw) return fallback;
  return /^\d{2}:\d{2}$/.test(raw) ? raw : fallback;
}

const topics = required('MQTT_TOPIC')
  .split(',')
  .map(function (t) {
    return t.trim();
  })
  .filter(Boolean);

const listenerSecret = required('LISTENER_SECRET');

const config = {
  mqtt: {
    host: required('MQTT_HOST'),
    port: process.env.MQTT_PORT || '8883',
    username: required('MQTT_USERNAME'),
    password: required('MQTT_PASSWORD'),
    topics: topics,
    reconnectPeriodMs: optionalNumber('MQTT_RECONNECT_MS', 5000),
    connectTimeoutMs: optionalNumber('MQTT_CONNECT_TIMEOUT_MS', 30000),
  },
  worker: {
    url: required('WORKER_URL'),
    listenerSecret: listenerSecret,
    retries: optionalNumber('WORKER_RETRIES', 3),
    timeoutMs: optionalNumber('WORKER_TIMEOUT_MS', 8000),
    backoffMs: optionalNumber('WORKER_BACKOFF_MS', 500),
  },
  control: {
    host: process.env.CONTROL_HOST || '0.0.0.0',
    port: optionalNumber('CONTROL_PORT', 3099),
    listenerSecret: listenerSecret,
  },
  schedule: {
    enabled: optionalBool('SCHEDULE_ENABLED', true),
    startTime: optionalHHMM('SCHEDULE_START', '06:00'),
    stopTime: optionalHHMM('SCHEDULE_STOP', '08:00'),
    timezone: process.env.SCHEDULE_TZ || 'Europe/Paris',
  },
  runtime: {
    heartbeatMs: optionalNumber('HEARTBEAT_MS', 60000),
    healthcheckUrl: process.env.HEALTHCHECK_URL || '',
    autoStart: optionalBool('MQTT_AUTO_START', false),
  },
};

config.mqtt.brokerUrl = 'mqtts://' + config.mqtt.host + ':' + config.mqtt.port;

module.exports = config;
