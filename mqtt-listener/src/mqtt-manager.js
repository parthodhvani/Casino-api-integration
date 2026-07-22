/**
 * MQTT connection manager.
 * Compatible with Node.js 14+ and mqtt@4.
 *
 * Owns the single MQTT client for this process. Connect only via startMQTT();
 * disconnect via stopMQTT(). The Node process itself is never terminated here.
 *
 * Forwards parsed messages directly to WordPress sites.
 */

'use strict';

const mqtt = require('mqtt');
const logger = require('./logger');
const { parseMessage } = require('./parser');
const { forwardToSites, pingHealthcheck } = require('./forwarder');

let client = null;
let running = false;
let transitioning = false;
let connectFn = mqtt.connect;

/**
 * Override the MQTT connect function (tests only).
 * @param {Function|null} fn
 */
function setConnectFn(fn) {
  connectFn = typeof fn === 'function' ? fn : mqtt.connect;
}

const state = {
  connectionState: 'stopped',
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

function isRunning() {
  return running;
}

function getStatus() {
  return {
    status: running ? 'Running' : 'Stopped',
    running: running,
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
 * @param {object} c mqtt client
 * @param {object} config
 */
function attachHandlers(c, config) {
  c.on('connect', function () {
    if (!running) {
      c.end(true);
      return;
    }
    state.connectionState = 'connected';
    state.lastError = null;
    logger.info('mqtt connected', { broker: config.mqtt.brokerUrl });

    c.subscribe(config.mqtt.topics, function (err) {
      if (err) {
        state.connectionState = 'error';
        state.lastError = err.message;
        logger.error('mqtt subscribe error', { error: err.message });
      } else {
        logger.info('mqtt subscribed', { topics: config.mqtt.topics });
      }
    });
  });

  c.on('reconnect', function () {
    if (!running) return;
    state.connectionState = 'reconnecting';
    logger.warn('mqtt reconnecting');
  });

  c.on('close', function () {
    if (running) {
      state.connectionState = 'offline';
      logger.warn('mqtt connection closed');
    }
  });

  c.on('offline', function () {
    if (running) {
      state.connectionState = 'offline';
      logger.warn('mqtt offline');
    }
  });

  c.on('error', function (err) {
    state.lastError = err.message;
    if (state.connectionState !== 'connected') {
      state.connectionState = 'error';
    }
    logger.error('mqtt error', { error: err.message });
  });

  c.on('message', async function (topic, message) {
    if (!running) return;

    state.connectionState = 'connected';
    state.lastError = null;

    const raw = message.toString();
    state.messages++;
    state.lastMessageAt = new Date().toISOString();
    state.lastRawMessage = raw.length > 500 ? raw.slice(0, 500) + '...' : raw;
    logger.info('message received', { topic: topic, raw: raw });

    const payload = parseMessage(raw);
    if (!payload) {
      logger.warn('message skipped (unknown or malformed)', { topic: topic, raw: raw });
      return;
    }

    if (payload.type === 'JPCONFIG') {
      state.lastConfigUpdate = new Date().toISOString();
    }

    const delivered = await forwardToSites(payload, config.wp);
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
 * @param {object} config
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
 * @param {boolean} force
 * @returns {Promise<void>}
 */
function endClient(force) {
  return new Promise(function (resolve) {
    if (!client) {
      resolve();
      return;
    }

    const c = client;
    client = null;

    c.removeAllListeners('message');
    c.removeAllListeners('connect');
    c.removeAllListeners('reconnect');
    c.removeAllListeners('close');
    c.removeAllListeners('offline');
    c.removeAllListeners('error');

    let done = false;
    const finish = function () {
      if (done) return;
      done = true;
      resolve();
    };

    try {
      if (c.end.length >= 3) {
        c.end(force, {}, finish);
      } else {
        c.end(force, finish);
      }
    } catch (err) {
      logger.warn('mqtt end error', { error: err.message });
      finish();
    }
    setTimeout(finish, 1500);
  });
}

module.exports = {
  startMQTT: startMQTT,
  stopMQTT: stopMQTT,
  isRunning: isRunning,
  getStatus: getStatus,
  setConnectFn: setConnectFn,
  _state: state,
  _getClient: function () {
    return client;
  },
};
