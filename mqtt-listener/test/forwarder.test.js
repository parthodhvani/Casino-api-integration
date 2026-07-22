/**
 * Forwarder tests (stubbed fetch). Compatible with Node 14 test runner.
 */
'use strict';

const httpMod = require('../src/http');
const { forwardToSites, forwardToSite } = require('../src/forwarder');

const payload = { type: 'JPUPDATE', jpId: 'O136', casId: 'IFCO', jpValue: '1', jpShared: '0' };
const baseConfig = {
  sites: [{ name: 'site1', url: 'https://example.com/wp-json/jackpot/v1/update' }],
  defaultSecret: 'secret',
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
    assert.strictEqual(await forwardToSites(payload, baseConfig), true);
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
    assert.strictEqual(await forwardToSites(payload, baseConfig), true);
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
    assert.strictEqual(await forwardToSites(payload, baseConfig), false);
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
      await forwardToSites(payload, Object.assign({}, baseConfig, { retries: 2 })),
      false
    );
    assert.strictEqual(calls, 3);
  } finally {
    httpMod.fetchWithTimeout = original;
  }
});

test('forwarder continues when one of two sites fails', async function () {
  let calls = 0;
  const original = httpMod.fetchWithTimeout;
  httpMod.fetchWithTimeout = async function (url) {
    calls++;
    if (String(url).indexOf('site-a') !== -1) {
      return fakeResponse('bad', 500);
    }
    return fakeResponse('ok', 200);
  };
  try {
    const cfg = {
      sites: [
        { name: 'a', url: 'https://site-a.example/update' },
        { name: 'b', url: 'https://site-b.example/update' },
      ],
      defaultSecret: 'secret',
      retries: 0,
      timeoutMs: 1000,
      backoffMs: 1,
    };
    assert.strictEqual(await forwardToSites(payload, cfg), true);
    assert.strictEqual(calls, 2);
  } finally {
    httpMod.fetchWithTimeout = original;
  }
});

test('forwardToSite sends x-signature header', async function () {
  let seen = null;
  const original = httpMod.fetchWithTimeout;
  httpMod.fetchWithTimeout = async function (url, options) {
    seen = options;
    return fakeResponse('ok', 200);
  };
  try {
    const body = JSON.stringify(payload);
    const result = await forwardToSite(
      { name: 's', url: 'https://example.com/u' },
      body,
      'secret',
      { retries: 0, timeoutMs: 1000, backoffMs: 1 }
    );
    assert.strictEqual(result.ok, true);
    assert.ok(seen.headers['x-signature']);
    assert.strictEqual(seen.headers['x-signature'].length, 64);
  } finally {
    httpMod.fetchWithTimeout = original;
  }
});
