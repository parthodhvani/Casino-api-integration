/**
 * Loads and validates configuration from environment variables.
 * Compatible with Node.js 14+ / cPanel + LiteSpeed Node.js App.
 *
 * Forwards directly to WordPress sites (Cloudflare Worker removed).
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

function parseWpSites(raw) {
  let arr;
  try {
    arr = JSON.parse(raw);
  } catch (err) {
    console.error('[fatal] WP_SITES must be valid JSON: ' + (err && err.message ? err.message : err));
    process.exit(1);
  }
  if (!Array.isArray(arr)) {
    console.error('[fatal] WP_SITES must be a JSON array');
    process.exit(1);
  }
  const sites = [];
  for (let i = 0; i < arr.length; i++) {
    const s = arr[i];
    if (!s || typeof s.url !== 'string' || !s.url) continue;
    const site = {
      name: typeof s.name === 'string' && s.name ? s.name : 'site' + (i + 1),
      url: s.url,
    };
    if (typeof s.secret === 'string' && s.secret) {
      site.secret = s.secret;
    }
    sites.push(site);
  }
  return sites;
}

/**
 * Resolve listen target for cPanel / LiteSpeed / local.
 *
 * LiteSpeed Node.js Selector (official docs): call server.listen() with NO
 * port/host. LSWS replaces the TCP socket with its own Unix socket.
 * Binding to 0.0.0.0:3099 or 127.0.0.1 breaks the proxy → HTTP 503.
 *
 * Override with BIND_MODE=explicit to use PORT / CONTROL_PORT (local/dev).
 *
 * @returns {{port: string|number|null, host: string|null, mode: string}}
 */
function resolveListenTarget() {
  const bindMode = String(process.env.BIND_MODE || '').toLowerCase();
  const raw = process.env.PORT;

  // Unix domain socket path from the host (rare but valid)
  if (raw !== undefined && raw !== null && String(raw).trim() !== '') {
    const s = String(raw).trim();
    if (!/^\d+$/.test(s) && s.indexOf('/') !== -1) {
      return { port: s, host: null, mode: 'unix-socket' };
    }
  }

  // Local / explicit bind (not cPanel LiteSpeed)
  if (bindMode === 'explicit' || bindMode === 'tcp') {
    if (raw !== undefined && raw !== null && String(raw).trim() !== '' && /^\d+$/.test(String(raw).trim())) {
      return { port: Number(String(raw).trim()), host: '127.0.0.1', mode: 'tcp-port' };
    }
    return {
      port: optionalNumber('CONTROL_PORT', 3099),
      host: process.env.CONTROL_HOST || '127.0.0.1',
      mode: 'fallback-control-port',
    };
  }

  // Default: cPanel + LiteSpeed — bare server.listen()
  return { port: null, host: null, mode: 'litespeed-auto' };
}

const topics = required('MQTT_TOPIC')
  .split(',')
  .map(function (t) {
    return t.trim();
  })
  .filter(Boolean);

const listenerSecret = required('LISTENER_SECRET');
const jackpotSecret = process.env.JACKPOT_SECRET || '';
const sites = parseWpSites(required('WP_SITES'));

if (sites.length === 0) {
  console.error('[fatal] WP_SITES must contain at least one site with a url');
  process.exit(1);
}

for (let i = 0; i < sites.length; i++) {
  if (!sites[i].secret && !jackpotSecret) {
    console.error(
      '[fatal] Site "' + sites[i].name + '" has no secret and JACKPOT_SECRET is not set'
    );
    process.exit(1);
  }
}

const listen = resolveListenTarget();

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
  wp: {
    sites: sites,
    defaultSecret: jackpotSecret,
    retries: optionalNumber('FORWARD_RETRIES', optionalNumber('WORKER_RETRIES', 3)),
    timeoutMs: optionalNumber('FORWARD_TIMEOUT_MS', optionalNumber('WORKER_TIMEOUT_MS', 8000)),
    backoffMs: optionalNumber('FORWARD_BACKOFF_MS', optionalNumber('WORKER_BACKOFF_MS', 500)),
  },
  control: {
    host: listen.host,
    port: listen.port,
    mode: listen.mode,
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
module.exports.resolveListenTarget = resolveListenTarget;
