/**
 * MQTT manager + control-server unit tests (no real broker).
 * Compatible with Node 14 test runner.
 */
'use strict';

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
  end(force, optsOrCb, cb) {
    this.ended = true;
    const callback = typeof optsOrCb === 'function' ? optsOrCb : cb;
    const self = this;
    setImmediate(function () {
      self.emit('close');
      if (callback) callback();
    });
  }
}

test('startMQTT / stopMQTT / already_running / getStatus', async function () {
  delete require.cache[require.resolve('../src/mqtt-manager')];
  const mgr = require('../src/mqtt-manager');

  let fake = null;
  mgr.setConnectFn(function () {
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
    wp: {
      sites: [{ name: 's', url: 'https://example.com/u' }],
      defaultSecret: 's',
      retries: 0,
      timeoutMs: 100,
      backoffMs: 1,
    },
    runtime: { healthcheckUrl: '' },
  };

  assert.strictEqual(mgr.isRunning(), false);
  assert.strictEqual(mgr.getStatus().status, 'Stopped');

  const started = await mgr.startMQTT(config);
  assert.strictEqual(started.status, 'started');
  assert.strictEqual(mgr.isRunning(), true);
  assert.ok(fake, 'fake client should be created');

  fake.emit('connect');
  assert.deepStrictEqual(fake.subscribed, ['/jp/gent']);
  assert.strictEqual(mgr.getStatus().connectionState, 'connected');

  const again = await mgr.startMQTT(config);
  assert.strictEqual(again.status, 'already_running');

  const stopped = await mgr.stopMQTT();
  assert.strictEqual(stopped.status, 'stopped');
  assert.strictEqual(mgr.isRunning(), false);
  assert.strictEqual(mgr.getStatus().status, 'Stopped');
  assert.strictEqual(fake.ended, true);

  const stoppedAgain = await mgr.stopMQTT();
  assert.strictEqual(stoppedAgain.status, 'already_stopped');

  mgr.setConnectFn(null);
});

test('control server auth + routes', async function () {
  delete require.cache[require.resolve('../src/control-server')];
  delete require.cache[require.resolve('../src/security')];
  const { createControlServer, listen } = require('../src/control-server');

  const calls = { start: 0, stop: 0 };
  const mqttManager = {
    isRunning: function () {
      return false;
    },
    getStatus: function () {
      return {
        status: 'Stopped',
        running: false,
        connectionState: 'stopped',
        lastSyncTime: null,
        lastMessageAt: null,
        lastConfigUpdate: null,
      };
    },
    startMQTT: async function () {
      calls.start++;
      return { status: 'started' };
    },
    stopMQTT: async function () {
      calls.stop++;
      return { status: 'stopped' };
    },
  };

  const config = {
    control: { listenerSecret: 'test-secret' },
  };

  const server = createControlServer({ config: config, mqttManager: mqttManager });
  await listen(server, { host: '127.0.0.1', port: 0 });
  const port = server.address().port;

  function request(method, reqPath, headers) {
    headers = headers || {};
    return new Promise(function (resolve, reject) {
      const req = http.request(
        { hostname: '127.0.0.1', port: port, path: reqPath, method: method, headers: headers },
        function (res) {
          let body = '';
          res.on('data', function (c) {
            body += c;
          });
          res.on('end', function () {
            resolve({ status: res.statusCode, body: JSON.parse(body || '{}') });
          });
        }
      );
      req.on('error', reject);
      req.end();
    });
  }

  try {
    const health = await request('GET', '/health');
    assert.strictEqual(health.status, 200);
    assert.strictEqual(health.body.ok, true);

    const unauth = await request('POST', '/start');
    assert.strictEqual(unauth.status, 401);

    const start = await request('POST', '/start', { 'x-listener-secret': 'test-secret' });
    assert.strictEqual(start.status, 200);
    assert.strictEqual(start.body.status, 'started');
    assert.strictEqual(calls.start, 1);

    const status = await request('GET', '/status', { 'x-listener-secret': 'test-secret' });
    assert.strictEqual(status.status, 200);
    assert.strictEqual(status.body.status, 'Stopped');

    const stop = await request('POST', '/stop', { 'x-listener-secret': 'test-secret' });
    assert.strictEqual(stop.status, 200);
    assert.strictEqual(stop.body.status, 'stopped');
    assert.strictEqual(calls.stop, 1);
  } finally {
    await new Promise(function (resolve) {
      server.close(resolve);
    });
  }
});

test('timingSafeEqualString', function () {
  const { timingSafeEqualString } = require('../src/security');
  assert.strictEqual(timingSafeEqualString('abc', 'abc'), true);
  assert.strictEqual(timingSafeEqualString('abc', 'abd'), false);
  assert.strictEqual(timingSafeEqualString('abc', 'abcd'), false);
});

test('zonedParts returns consistent shape', function () {
  const { zonedParts } = require('../src/scheduler');
  const parts = zonedParts(new Date('2026-07-14T04:00:00.000Z'), 'UTC');
  assert.strictEqual(parts.year, 2026);
  assert.strictEqual(parts.month, 7);
  assert.strictEqual(parts.day, 14);
  assert.strictEqual(parts.hour, 4);
  assert.strictEqual(parts.minute, 0);
});
