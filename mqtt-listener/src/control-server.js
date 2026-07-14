/**
 * HTTP control server for MQTT start / stop / status.
 *
 * Endpoints (all require x-listener-secret):
 *   POST /start  → startMQTT()
 *   POST /stop   → stopMQTT()
 *   GET  /status → getStatus()
 *   GET  /health → liveness (no auth; process is up)
 */

const http = require('http');
const { timingSafeEqualString } = require('./security');
const logger = require('./logger');

/**
 * @param {object} options
 * @param {object} options.config
 * @param {object} options.mqttManager  { startMQTT, stopMQTT, getStatus }
 * @returns {http.Server}
 */
function createControlServer({ config, mqttManager }) {
  const server = http.createServer(async (req, res) => {
    const url = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);
    const path = url.pathname.replace(/\/+$/, '') || '/';
    const method = (req.method || 'GET').toUpperCase();

    // Liveness probe — no secret (used by load balancers / systemd).
    if (method === 'GET' && (path === '/health' || path === '/')) {
      return sendJson(res, 200, {
        ok: true,
        service: 'jackpot-mqtt-listener',
        version: '3.1.0',
        mqtt: mqttManager.isRunning() ? 'Running' : 'Stopped',
      });
    }

    if (!authenticate(req, config.control.listenerSecret)) {
      logger.warn('control unauthorized', { path, method });
      return sendJson(res, 401, { error: 'unauthorized' });
    }

    try {
      if (method === 'POST' && path === '/start') {
        const result = await mqttManager.startMQTT(config);
        const code = result.status === 'already_running' ? 200 : result.status === 'busy' ? 409 : 200;
        return sendJson(res, code, result);
      }

      if (method === 'POST' && path === '/stop') {
        const result = await mqttManager.stopMQTT();
        const code = result.status === 'busy' ? 409 : 200;
        return sendJson(res, code, result);
      }

      if (method === 'GET' && path === '/status') {
        return sendJson(res, 200, mqttManager.getStatus());
      }

      return sendJson(res, 404, { error: 'not found' });
    } catch (err) {
      logger.error('control handler error', { error: err.message, path, method });
      return sendJson(res, 500, { error: 'internal error', message: err.message });
    }
  });

  return server;
}

/**
 * Constant-time listener-secret check.
 *
 * @param {http.IncomingMessage} req
 * @param {string} expected
 * @returns {boolean}
 */
function authenticate(req, expected) {
  if (!expected) return false;
  const provided = req.headers['x-listener-secret'] || '';
  return timingSafeEqualString(String(provided), String(expected));
}

/**
 * @param {http.ServerResponse} res
 * @param {number} status
 * @param {object} body
 */
function sendJson(res, status, body) {
  const payload = JSON.stringify(body);
  res.writeHead(status, {
    'content-type': 'application/json',
    'cache-control': 'no-store',
  });
  res.end(payload);
}

/**
 * Bind the control server.
 *
 * @param {http.Server} server
 * @param {{host:string,port:number}} bind
 * @returns {Promise<http.Server>}
 */
function listen(server, bind) {
  return new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(bind.port, bind.host, () => {
      server.removeListener('error', reject);
      resolve(server);
    });
  });
}

module.exports = { createControlServer, listen };
