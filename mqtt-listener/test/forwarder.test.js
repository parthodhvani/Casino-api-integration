/**
 * Forwarder tests (stubbed fetch). Compatible with Node 14 test runner.
 */
'use strict';

const httpMod = require('../src/http');
const { forwardToWorker } = require('../src/forwarder');

const payload = { type: 'JPUPDATE', jpId: 'O136' };
const baseConfig = {
  url: 'https://worker.example/x',
  listenerSecret: 'secret',
  retries: 3,
  timeoutMs: 1000,
  backoffMs: 1,
};

function fakeResponse(body, status) {
  return {
    ok: status >= 200 && status < 300,
    status: status,
    text: function () {
      return Promise.resolve(body);
    },
  };
}

test('forwarder returns true on 2xx', async function () {
  const original = httpMod.fetchWithTimeout;
  httpMod.fetchWithTimeout = async function () {
    return fakeResponse('{"ok":true}', 200);
  };
  try {
    // Re-require forwarder won't pick up mutated export of fetchWithTimeout
    // because forwarder already closed over require('./http'). Mutating the
    // exported function on the same module object works.
    assert.strictEqual(await forwardToWorker(payload, baseConfig), true);
  } finally {
    httpMod.fetchWithTimeout = original;
  }
});

test('forwarder retries on 5xx then succeeds', async function () {
  let calls = 0;
  const original = httpMod.fetchWithTimeout;
  httpMod.fetchWithTimeout = async function () {
    calls++;
    return calls < 3 ? fakeResponse('e', 502) : fakeResponse('ok', 200);
  };
  try {
    assert.strictEqual(await forwardToWorker(payload, baseConfig), true);
    assert.strictEqual(calls, 3);
  } finally {
    httpMod.fetchWithTimeout = original;
  }
});

test('forwarder does not retry on 4xx', async function () {
  let calls = 0;
  const original = httpMod.fetchWithTimeout;
  httpMod.fetchWithTimeout = async function () {
    calls++;
    return fakeResponse('bad', 401);
  };
  try {
    assert.strictEqual(await forwardToWorker(payload, baseConfig), false);
    assert.strictEqual(calls, 1);
  } finally {
    httpMod.fetchWithTimeout = original;
  }
});

test('forwarder gives up after retries on persistent error', async function () {
  let calls = 0;
  const original = httpMod.fetchWithTimeout;
  httpMod.fetchWithTimeout = async function () {
    calls++;
    throw new Error('network down');
  };
  try {
    assert.strictEqual(
      await forwardToWorker(payload, Object.assign({}, baseConfig, { retries: 2 })),
      false
    );
    assert.strictEqual(calls, 3);
  } finally {
    httpMod.fetchWithTimeout = original;
  }
});
