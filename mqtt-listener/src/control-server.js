/**
 * HTTP control server for MQTT start / stop / status.
 * Compatible with Node.js 14+ / cPanel + LiteSpeed.
 *
 * Endpoints:
 *   POST /start  → startMQTT()
 *   POST /stop   → stopMQTT()
 *   GET  /status → getStatus()
 *   GET  /health → liveness (no auth)
 *
 * Auth (either one):
 *   x-listener-secret: LISTENER_SECRET
 *   X-Signature: HMAC-SHA256(JACKPOT_SECRET, body)
 */

'use strict';

const fs = require('fs');
const http = require('http');
const { URL } = require('url');
const crypto = require('crypto');
const logger = require('./logger');

// Inline crypto helpers so a stale/partial security.js cannot break auth
// (avoids circular-dependency / missing-export issues on cPanel uploads).
function timingSafeEqualString(a, b) {
  if (typeof a !== 'string' || typeof b !== 'string') return false;
  const bufA = Buffer.from(a);
  const bufB = Buffer.from(b);
  if (bufA.length !== bufB.length) {
    try {
      crypto.timingSafeEqual(bufA, Buffer.alloc(bufA.length));
    } catch (_) {
      /* ignore */
    }
    return false;
  }
  return crypto.timingSafeEqual(bufA, bufB);
}

function hmacSha256Hex(secret, message) {
  return crypto.createHmac('sha256', String(secret)).update(String(message), 'utf8').digest('hex');
}

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
 * @returns {Promise<string>}
 */
function readBody(req) {
  return new Promise(function (resolve, reject) {
    const chunks = [];
    req.on('data', function (c) {
      chunks.push(c);
    });
    req.on('end', function () {
      resolve(Buffer.concat(chunks).toString('utf8'));
    });
    req.on('error', reject);
  });
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
      version: '3.2.1',
      node: process.versions.node,
      listenMode: config.control.mode || null,
      mqtt: mqttManager.isRunning() ? 'Running' : 'Stopped',
    });
  }

  const needsBody = method === 'POST';
  const bodyText = needsBody ? await readBody(req) : '';

  if (!authenticate(req, config, bodyText)) {
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
 * @param {object} config
 * @param {string} bodyText
 * @returns {boolean}
 */
function authenticate(req, config, bodyText) {
  const listenerSecret = config.control && config.control.listenerSecret;
  const provided = req.headers['x-listener-secret'] || '';
  if (listenerSecret && timingSafeEqualString(String(provided), String(listenerSecret))) {
    return true;
  }

  const jackpotSecret =
    (config.wp && config.wp.defaultSecret) || process.env.JACKPOT_SECRET || '';
  const signature = req.headers['x-signature'] || '';
  if (jackpotSecret && signature) {
    const expected = hmacSha256Hex(jackpotSecret, bodyText || '');
    if (timingSafeEqualString(String(signature), String(expected))) {
      return true;
    }
  }

  return false;
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
 * Bind HTTP server.
 *
 * cPanel + LiteSpeed: mode "litespeed-auto" → server.listen(callback) with NO
 * port/host. LSWS injects its Unix socket. Specifying 0.0.0.0:3099 causes 503.
 *
 * @param {http.Server} server
 * @param {{host:string|null,port:string|number|null,mode?:string}} bind
 * @returns {Promise<http.Server>}
 */
function listen(server, bind) {
  return new Promise(function (resolve, reject) {
    server.once('error', reject);

    const onListening = function () {
      server.removeListener('error', reject);
      const addr = server.address();
      logger.info('control bind ready', {
        mode: bind.mode || null,
        address: addr,
      });
      resolve(server);
    };

    const mode = bind.mode || '';
    const port = bind.port;

    // Official LiteSpeed / CloudLinux Node selector pattern
    if (mode === 'litespeed-auto' || (port == null && bind.host == null && mode !== 'unix-socket')) {
      server.listen(onListening);
      return;
    }

    // Unix socket: remove stale file then listen on path only.
    if (typeof port === 'string' && port.indexOf('/') !== -1) {
      try {
        if (fs.existsSync(port)) {
          fs.unlinkSync(port);
        }
      } catch (err) {
        logger.warn('could not remove stale socket', {
          socket: port,
          error: err && err.message ? err.message : String(err),
        });
      }
      server.listen(port, onListening);
      return;
    }

    if (bind.host) {
      server.listen(port, bind.host, onListening);
    } else {
      server.listen(port, onListening);
    }
  });
}

module.exports = { createControlServer: createControlServer, listen: listen };
