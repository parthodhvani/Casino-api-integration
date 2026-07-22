/**
 * MQTT Listener — entry point (Node.js 14+ / cPanel + LiteSpeed compatible).
 *
 * Starts an HTTP control API and (optionally) a daily scheduler. The MQTT
 * connection is NOT opened on boot unless MQTT_AUTO_START=true.
 *
 * On cPanel LiteSpeed: uses bare server.listen() so LSWS can inject its socket.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const config = require('./config');
const logger = require('./logger');
const mqttManager = require('./mqtt-manager');
const { createControlServer, listen } = require('./control-server');
const { startScheduler } = require('./scheduler');

function writeBootStatus(payload) {
  try {
    const file = path.join(process.cwd(), 'boot-status.json');
    fs.writeFileSync(file, JSON.stringify(payload, null, 2));
  } catch (err) {
    logger.warn('could not write boot-status.json', {
      error: err && err.message ? err.message : String(err),
    });
  }
}

async function main() {
  const bootInfo = {
    at: new Date().toISOString(),
    listenMode: config.control.mode,
    listenPort: config.control.port,
    listenHost: config.control.host,
    envPort: process.env.PORT || null,
    controlPortEnv: process.env.CONTROL_PORT || null,
    controlHostEnv: process.env.CONTROL_HOST || null,
    bindModeEnv: process.env.BIND_MODE || null,
    node: process.versions.node,
    cwd: process.cwd(),
    wpSites: (config.wp.sites || []).length,
    version: '3.2.1',
  };

  logger.info('boot', bootInfo);
  writeBootStatus(Object.assign({ phase: 'starting' }, bootInfo));

  const server = createControlServer({ config: config, mqttManager: mqttManager });
  await listen(server, {
    host: config.control.host,
    port: config.control.port,
    mode: config.control.mode,
  });

  const addr = server.address();
  logger.info('control server listening', {
    mode: config.control.mode,
    host: config.control.host,
    port: config.control.port,
    address: addr,
    node: process.versions.node,
    endpoints: ['POST /start', 'POST /stop', 'GET /status', 'GET /health'],
  });

  writeBootStatus(
    Object.assign({}, bootInfo, {
      phase: 'listening',
      address: addr,
      listenedAt: new Date().toISOString(),
    })
  );

  const scheduler = startScheduler({ config: config, mqttManager: mqttManager });

  const heartbeat = setInterval(function () {
    const status = mqttManager.getStatus();
    logger.info('heartbeat', {
      running: status.running,
      connectionState: status.connectionState,
      messages: status.messages,
      forwarded: status.forwarded,
      failed: status.failed,
      lastMessageAt: status.lastMessageAt,
      lastSyncTime: status.lastSyncTime,
    });
  }, config.runtime.heartbeatMs);
  if (typeof heartbeat.unref === 'function') {
    heartbeat.unref();
  }

  if (config.runtime.autoStart) {
    logger.info('MQTT_AUTO_START enabled — connecting now');
    await mqttManager.startMQTT(config);
  } else {
    logger.info('MQTT idle — waiting for /start or schedule', {
      scheduleEnabled: config.schedule.enabled,
      start: config.schedule.startTime,
      stop: config.schedule.stopTime,
      timezone: config.schedule.timezone,
    });
  }

  let shuttingDown = false;
  async function shutdown(signal) {
    if (shuttingDown) return;
    shuttingDown = true;
    logger.info('shutting down', { signal: signal });
    clearInterval(heartbeat);
    scheduler.stop();
    try {
      await mqttManager.stopMQTT();
    } catch (err) {
      logger.warn('stopMQTT during shutdown failed', { error: err.message });
    }
    await new Promise(function (resolve) {
      server.close(function () {
        resolve();
      });
      setTimeout(resolve, 1000);
    });
    process.exit(0);
  }

  process.on('SIGINT', function () {
    shutdown('SIGINT');
  });
  process.on('SIGTERM', function () {
    shutdown('SIGTERM');
  });
}

main().catch(function (err) {
  logger.error('fatal startup error', {
    error: err && err.message ? err.message : String(err),
    stack: err && err.stack ? err.stack : null,
  });
  try {
    writeBootStatus({
      phase: 'fatal',
      at: new Date().toISOString(),
      error: err && err.message ? err.message : String(err),
      stack: err && err.stack ? err.stack : null,
    });
  } catch (_) {
    /* ignore */
  }
  process.exit(1);
});

module.exports = { mqttManager: mqttManager };
