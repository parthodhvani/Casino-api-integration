/**
 * MQTT manager + control-server unit tests (no real broker).
 * Run with: npm test
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const http = require('http');
const EventEmitter = require('events');

class FakeClient extends EventEmitter {
  constructor() {
    super();
    this.ended = false;
    this.subscribed = null;
  }
  subscribe(topics, cb) {
    this.subscribed = topics;
    if (cb) cb(null);
  }
  end(force, opts, cb) {
    this.ended = true;
    if (typeof opts === 'function') cb = opts;
    setImmediate(() => {
      this.emit('close');
      if (cb) cb();
    });
  }
}

test('startMQTT / stopMQTT / already_running / getStatus', async () => {
  delete require.cache[require.resolve('../src/mqtt-manager')];
  const mgr = require('../src/mqtt-manager');

  let fake = null;
  mgr.setConnectFn(() => {
    fake = new FakeClient();
    return fake;
  });

  const config = {
    mqtt: {
      brokerUrl: 'mqtts://example:8883',
      username: 'u',
      password: 'p',
      topics: ['/jp/gent'],
      reconnectPeriodMs: 1000,
      connectTimeoutMs: 1000,
    },
    worker: { url: 'https://w', listenerSecret: 's', retries: 0, timeoutMs: 100, backoffMs: 1 },
    runtime: { healthcheckUrl: '' },
  };

  assert.equal(mgr.isRunning(), false);
  assert.equal(mgr.getStatus().status, 'Stopped');

  const started = await mgr.startMQTT(config);
  assert.equal(started.status, 'started');
  assert.equal(mgr.isRunning(), true);
  assert.ok(fake, 'fake client should be created');

  fake.emit('connect');
  assert.deepEqual(fake.subscribed, ['/jp/gent']);
  assert.equal(mgr.getStatus().connectionState, 'connected');

  const again = await mgr.startMQTT(config);
  assert.equal(again.status, 'already_running');

  const stopped = await mgr.stopMQTT();
  assert.equal(stopped.status, 'stopped');
  assert.equal(mgr.isRunning(), false);
  assert.equal(mgr.getStatus().status, 'Stopped');
  assert.equal(fake.ended, true);

  const stoppedAgain = await mgr.stopMQTT();
  assert.equal(stoppedAgain.status, 'already_stopped');

  mgr.setConnectFn(null);
});

test('control server auth + routes', async (t) => {
  delete require.cache[require.resolve('../src/control-server')];
  delete require.cache[require.resolve('../src/security')];
  const { createControlServer, listen } = require('../src/control-server');

  const calls = { start: 0, stop: 0 };
  const mqttManager = {
    isRunning: () => false,
    getStatus: () => ({
      status: 'Stopped',
      running: false,
      connectionState: 'stopped',
      lastSyncTime: null,
      lastMessageAt: null,
      lastConfigUpdate: null,
    }),
    startMQTT: async () => {
      calls.start++;
      return { status: 'started' };
    },
    stopMQTT: async () => {
      calls.stop++;
      return { status: 'stopped' };
    },
  };

  const config = {
    control: { listenerSecret: 'test-secret' },
  };

  const server = createControlServer({ config, mqttManager });
  await listen(server, { host: '127.0.0.1', port: 0 });
  const { port } = server.address();

  t.after(() => new Promise((resolve) => server.close(resolve)));

  const request = (method, path, headers = {}) =>
    new Promise((resolve, reject) => {
      const req = http.request(
        { hostname: '127.0.0.1', port, path, method, headers },
        (res) => {
          let body = '';
          res.on('data', (c) => (body += c));
          res.on('end', () => {
            resolve({ status: res.statusCode, body: JSON.parse(body || '{}') });
          });
        }
      );
      req.on('error', reject);
      req.end();
    });

  const health = await request('GET', '/health');
  assert.equal(health.status, 200);
  assert.equal(health.body.ok, true);

  const unauth = await request('POST', '/start');
  assert.equal(unauth.status, 401);

  const start = await request('POST', '/start', { 'x-listener-secret': 'test-secret' });
  assert.equal(start.status, 200);
  assert.equal(start.body.status, 'started');
  assert.equal(calls.start, 1);

  const status = await request('GET', '/status', { 'x-listener-secret': 'test-secret' });
  assert.equal(status.status, 200);
  assert.equal(status.body.status, 'Stopped');

  const stop = await request('POST', '/stop', { 'x-listener-secret': 'test-secret' });
  assert.equal(stop.status, 200);
  assert.equal(stop.body.status, 'stopped');
  assert.equal(calls.stop, 1);
});

test('timingSafeEqualString', () => {
  const { timingSafeEqualString } = require('../src/security');
  assert.equal(timingSafeEqualString('abc', 'abc'), true);
  assert.equal(timingSafeEqualString('abc', 'abd'), false);
  assert.equal(timingSafeEqualString('abc', 'abcd'), false);
});

test('zonedParts returns consistent shape', () => {
  const { zonedParts } = require('../src/scheduler');
  const parts = zonedParts(new Date('2026-07-14T04:00:00.000Z'), 'UTC');
  assert.equal(parts.year, 2026);
  assert.equal(parts.month, 7);
  assert.equal(parts.day, 14);
  assert.equal(parts.hour, 4);
  assert.equal(parts.minute, 0);
});
