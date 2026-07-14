/**
 * MQTT Listener — entry point.
 *
 * Starts an HTTP control API and (optionally) a daily scheduler. The MQTT
 * connection is NOT opened on boot unless MQTT_AUTO_START=true. Connect via
 * startMQTT() — from POST /start, the built-in schedule, or external cron.
 *
 * Features: controllable MQTT, auto-reconnect while running, heartbeat,
 * forward retry, graceful process shutdown (SIGINT/SIGTERM).
 *
 * Testing:    run on your laptop (`npm start`).
 * Production: run on an always-on host (Render/Fly/VPS) with the same env vars.
 */

const config = require('./config');
const logger = require('./logger');
const mqttManager = require('./mqtt-manager');
const { createControlServer, listen } = require('./control-server');
const { startScheduler } = require('./scheduler');

async function main() {
  const server = createControlServer({ config, mqttManager });
  await listen(server, { host: config.control.host, port: config.control.port });
  logger.info('control server listening', {
    host: config.control.host,
    port: config.control.port,
    endpoints: ['POST /start', 'POST /stop', 'GET /status', 'GET /health'],
  });

  const scheduler = startScheduler({ config, mqttManager });

  // Heartbeat — periodic status so silent feeds are visible in logs/monitoring.
  const heartbeat = setInterval(() => {
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

  // Graceful process shutdown for both Ctrl-C (SIGINT) and host stop (SIGTERM).
  let shuttingDown = false;
  async function shutdown(signal) {
    if (shuttingDown) return;
    shuttingDown = true;
    logger.info('shutting down', { signal });
    clearInterval(heartbeat);
    scheduler.stop();
    try {
      await mqttManager.stopMQTT();
    } catch (err) {
      logger.warn('stopMQTT during shutdown failed', { error: err.message });
    }
    await new Promise((resolve) => {
      server.close(() => resolve());
      setTimeout(resolve, 1000);
    });
    process.exit(0);
  }

  process.on('SIGINT', () => {
    shutdown('SIGINT');
  });
  process.on('SIGTERM', () => {
    shutdown('SIGTERM');
  });
}

main().catch((err) => {
  logger.error('fatal startup error', { error: err.message });
  process.exit(1);
});

module.exports = { mqttManager };
