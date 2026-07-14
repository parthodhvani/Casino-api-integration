/**
 * Forwarder tests (stubbed fetch). Run with: npm test  (node --test)
 */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { forwardToWorker } = require('../src/forwarder');

const payload = { type: 'JPUPDATE', jpId: 'O136' };
const baseConfig = {
  url: 'https://worker.example/x',
  listenerSecret: 'secret',
  retries: 3,
  timeoutMs: 1000,
  backoffMs: 1,
};

test('returns true on 2xx', async () => {
  const original = globalThis.fetch;
  globalThis.fetch = async () => new Response('{"ok":true}', { status: 200 });
  try {
    assert.equal(await forwardToWorker(payload, baseConfig), true);
  } finally {
    globalThis.fetch = original;
  }
});

test('retries on 5xx then succeeds', async () => {
  let calls = 0;
  const original = globalThis.fetch;
  globalThis.fetch = async () => {
    calls++;
    return calls < 3 ? new Response('e', { status: 502 }) : new Response('ok', { status: 200 });
  };
  try {
    assert.equal(await forwardToWorker(payload, baseConfig), true);
    assert.equal(calls, 3);
  } finally {
    globalThis.fetch = original;
  }
});

test('does not retry on 4xx', async () => {
  let calls = 0;
  const original = globalThis.fetch;
  globalThis.fetch = async () => {
    calls++;
    return new Response('bad', { status: 401 });
  };
  try {
    assert.equal(await forwardToWorker(payload, baseConfig), false);
    assert.equal(calls, 1);
  } finally {
    globalThis.fetch = original;
  }
});

test('gives up after retries on persistent error', async () => {
  let calls = 0;
  const original = globalThis.fetch;
  globalThis.fetch = async () => {
    calls++;
    throw new Error('network down');
  };
  try {
    assert.equal(await forwardToWorker(payload, { ...baseConfig, retries: 2 }), false);
    assert.equal(calls, 3);
  } finally {
    globalThis.fetch = original;
  }
});
