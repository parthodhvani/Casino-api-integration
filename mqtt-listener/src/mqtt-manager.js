/**
 * MQTT connection manager.
 *
 * Owns the single MQTT client for this process. Connect only via startMQTT();
 * disconnect via stopMQTT(). The Node process itself is never terminated here.
 *
 * Guarantees:
 *   - At most one active MQTT client / subscription set.
 *   - Safe reconnect while "running"; no reconnect after an intentional stop.
 *   - Status fields for the control API and admin UI.
 */

const mqtt = require('mqtt');
const logger = require('./logger');
const { parseMessage } = require('./parser');
const { forwardToWorker, pingHealthcheck } = require('./forwarder');

/** @type {import('mqtt').MqttClient|null} */
let client = null;

/** True while the manager intends to keep a connection (even during reconnect). */
let running = false;

/** True while startMQTT()/stopMQTT() is in flight (serialize control calls). */
let transitioning = false;

/** Injectable for unit tests (defaults to mqtt.connect). */
let connectFn = mqtt.connect;

/**
 * Override the MQTT connect function (tests only).
 *
 * @param {Function} fn
 */
function setConnectFn(fn) {
  connectFn = typeof fn === 'function' ? fn : mqtt.connect;
}

const state = {
  connectionState: 'stopped', // stopped | connecting | connected | reconnecting | offline | error
  messages: 0,
  forwarded: 0,
  failed: 0,
  lastMessageAt: null,
  lastSyncTime: null,
  lastConfigUpdate: null,
  lastRawMessage: null,
  lastError: null,
  startedAt: null,
  stoppedAt: null,
};

/**
 * @returns {boolean}
 */
function isRunning() {
  return running;
}

/**
 * Snapshot used by GET /status and the WordPress admin UI.
 *
 * @returns {object}
 */
function getStatus() {
  return {
    status: running ? 'Running' : 'Stopped',
    running,
    connectionState: state.connectionState,
    lastSyncTime: state.lastSyncTime,
    lastMessageAt: state.lastMessageAt,
    lastConfigUpdate: state.lastConfigUpdate,
    lastRawMessage: state.lastRawMessage,
    lastError: state.lastError,
    messages: state.messages,
    forwarded: state.forwarded,
    failed: state.failed,
    startedAt: state.startedAt,
    stoppedAt: state.stoppedAt,
  };
}

/**
 * Attach event handlers once per client instance.
 *
 * @param {import('mqtt').MqttClient} c
 * @param {object} config
 */
function attachHandlers(c, config) {
  c.on('connect', () => {
    if (!running) {
      // Intentional stop raced a connect — drop immediately.
      c.end(true);
      return;
    }
    state.connectionState = 'connected';
    state.lastError = null;
    logger.info('mqtt connected', { broker: config.mqtt.brokerUrl });

    c.subscribe(config.mqtt.topics, (err) => {
      if (err) {
        state.connectionState = 'error';
        state.lastError = err.message;
        logger.error('mqtt subscribe error', { error: err.message });
      } else {
        logger.info('mqtt subscribed', { topics: config.mqtt.topics });
      }
    });
  });

  c.on('reconnect', () => {
    if (!running) return;
    state.connectionState = 'reconnecting';
    logger.warn('mqtt reconnecting');
  });

  c.on('close', () => {
    if (running) {
      state.connectionState = 'offline';
      logger.warn('mqtt connection closed');
    }
  });

  c.on('offline', () => {
    if (running) {
      state.connectionState = 'offline';
      logger.warn('mqtt offline');
    }
  });

  c.on('error', (err) => {
    state.lastError = err.message;
    state.connectionState = 'error';
    logger.error('mqtt error', { error: err.message });
  });

  c.on('message', async (topic, message) => {
    if (!running) return;

    const raw = message.toString();
    state.messages++;
    state.lastMessageAt = new Date().toISOString();
    state.lastRawMessage = raw.length > 500 ? raw.slice(0, 500) + '…' : raw;
    logger.info('message received', { topic, raw });

    const payload = parseMessage(raw);
    if (!payload) {
      logger.warn('message skipped (unknown or malformed)', { topic, raw });
      return;
    }

    if (payload.type === 'JPCONFIG') {
      state.lastConfigUpdate = new Date().toISOString();
    }

    const delivered = await forwardToWorker(payload, config.worker);
    if (delivered) {
      state.forwarded++;
      state.lastSyncTime = new Date().toISOString();
      await pingHealthcheck(config.runtime.healthcheckUrl);
    } else {
      state.failed++;
    }
  });
}

/**
 * Connect to the broker and subscribe. Idempotent: if already running,
 * returns { status: 'already_running' } without opening a second connection.
 *
 * @param {object} config App config from ./config
 * @returns {Promise<{status:string, connectionState?:string}>}
 */
async function startMQTT(config) {
  if (running && client) {
    return { status: 'already_running', connectionState: state.connectionState };
  }

  if (transitioning) {
    return { status: 'busy', connectionState: state.connectionState };
  }

  transitioning = true;
  try {
    // Defensive: tear down any leftover client before creating a new one.
    if (client) {
      await endClient(true);
    }

    running = true;
    state.connectionState = 'connecting';
    state.startedAt = new Date().toISOString();
    state.stoppedAt = null;
    state.lastError = null;

    client = connectFn(config.mqtt.brokerUrl, {
      username: config.mqtt.username,
      password: config.mqtt.password,
      reconnectPeriod: config.mqtt.reconnectPeriodMs,
      connectTimeout: config.mqtt.connectTimeoutMs,
    });

    attachHandlers(client, config);
    logger.info('mqtt start requested', { broker: config.mqtt.brokerUrl });

    return { status: 'started', connectionState: state.connectionState };
  } finally {
    transitioning = false;
  }
}

/**
 * Gracefully disconnect MQTT. Does not exit the Node process.
 *
 * @returns {Promise<{status:string}>}
 */
async function stopMQTT() {
  if (!running && !client) {
    return { status: 'already_stopped' };
  }

  if (transitioning) {
    return { status: 'busy' };
  }

  transitioning = true;
  try {
    running = false;
    await endClient(true);
    state.connectionState = 'stopped';
    state.stoppedAt = new Date().toISOString();
    logger.info('mqtt stopped');
    return { status: 'stopped' };
  } finally {
    transitioning = false;
  }
}

/**
 * End the current client and clear the reference.
 *
 * @param {boolean} force
 * @returns {Promise<void>}
 */
function endClient(force) {
  return new Promise((resolve) => {
    if (!client) {
      resolve();
      return;
    }

    const c = client;
    client = null;

    // Prevent reconnect / close noise after an intentional stop.
    c.removeAllListeners('message');
    c.removeAllListeners('connect');
    c.removeAllListeners('reconnect');
    c.removeAllListeners('close');
    c.removeAllListeners('offline');
    c.removeAllListeners('error');

    let done = false;
    const finish = () => {
      if (done) return;
      done = true;
      resolve();
    };

    try {
      c.end(force, {}, finish);
    } catch (err) {
      logger.warn('mqtt end error', { error: err.message });
      finish();
    }
    setTimeout(finish, 1500);
  });
}

module.exports = {
  startMQTT,
  stopMQTT,
  isRunning,
  getStatus,
  setConnectFn,
  // Exposed for tests / shutdown hooks.
  _state: state,
  _getClient: () => client,
};
