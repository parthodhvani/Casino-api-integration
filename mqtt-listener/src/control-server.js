/**
 * HTTP control server for MQTT start / stop / status.
 * Compatible with Node.js 14+ (uses url.parse when URL global quirks appear).
 *
 * Endpoints (all require x-listener-secret):
 *   POST /start  → startMQTT()
 *   POST /stop   → stopMQTT()
 *   GET  /status → getStatus()
 *   GET  /health → liveness (no auth; process is up)
 */

'use strict';

const http = require('http');
const { URL } = require('url');
const { timingSafeEqualString } = require('./security');
const logger = require('./logger');

/**
 * @param {object} options
 * @param {object} options.config
 * @param {object} options.mqttManager
 * @returns {http.Server}
 */
function createControlServer(options) {
  const config = options.config;
  const mqttManager = options.mqttManager;

  const server = http.createServer(function (req, res) {
    // Wrap async work so Node 14 server callback stays sync-safe.
    handleRequest(req, res, config, mqttManager).catch(function (err) {
      logger.error('control handler error', {
        error: err && err.message ? err.message : String(err),
      });
      if (!res.headersSent) {
        sendJson(res, 500, { error: 'internal error', message: String(err && err.message) });
      }
    });
  });

  return server;
}

/**
 * @param {http.IncomingMessage} req
 * @param {http.ServerResponse} res
 * @param {object} config
 * @param {object} mqttManager
 */
async function handleRequest(req, res, config, mqttManager) {
  const host = req.headers.host || 'localhost';
  const url = new URL(req.url || '/', 'http://' + host);
  let path = url.pathname.replace(/\/+$/, '');
  if (!path) path = '/';
  const method = (req.method || 'GET').toUpperCase();

  if (method === 'GET' && (path === '/health' || path === '/')) {
    return sendJson(res, 200, {
      ok: true,
      service: 'jackpot-mqtt-listener',
      version: '3.1.1',
      node: process.versions.node,
      mqtt: mqttManager.isRunning() ? 'Running' : 'Stopped',
    });
  }

  if (!authenticate(req, config.control.listenerSecret)) {
    logger.warn('control unauthorized', { path: path, method: method });
    return sendJson(res, 401, { error: 'unauthorized' });
  }

  if (method === 'POST' && path === '/start') {
    const result = await mqttManager.startMQTT(config);
    const code = result.status === 'busy' ? 409 : 200;
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
}

/**
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
 * @param {http.Server} server
 * @param {{host:string,port:number}} bind
 * @returns {Promise<http.Server>}
 */
function listen(server, bind) {
  return new Promise(function (resolve, reject) {
    server.once('error', reject);
    server.listen(bind.port, bind.host, function () {
      server.removeListener('error', reject);
      resolve(server);
    });
  });
}

module.exports = { createControlServer: createControlServer, listen: listen };
