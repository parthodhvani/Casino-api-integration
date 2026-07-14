/**
 * MQTT Listener — entry point (Node.js 14+ / cPanel compatible).
 *
 * Starts an HTTP control API and (optionally) a daily scheduler. The MQTT
 * connection is NOT opened on boot unless MQTT_AUTO_START=true.
 *
 * Still talks to the same Cloudflare Worker + WordPress plugin contracts:
 *   POST WORKER_URL with x-listener-secret + JSON body
 *   Control: POST /start, POST /stop, GET /status
 */

'use strict';

const config = require('./config');
const logger = require('./logger');
const mqttManager = require('./mqtt-manager');
const { createControlServer, listen } = require('./control-server');
const { startScheduler } = require('./scheduler');

async function main() {
  const server = createControlServer({ config: config, mqttManager: mqttManager });
  await listen(server, { host: config.control.host, port: config.control.port });
  logger.info('control server listening', {
    host: config.control.host,
    port: config.control.port,
    node: process.versions.node,
    endpoints: ['POST /start', 'POST /stop', 'GET /status', 'GET /health'],
  });

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
  logger.error('fatal startup error', { error: err.message });
  process.exit(1);
});

module.exports = { mqttManager: mqttManager };
