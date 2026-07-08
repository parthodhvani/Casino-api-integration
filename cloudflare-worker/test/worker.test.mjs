/**
 * Unit tests for the Worker's pure modules.
 * Run with: npm test  (node --test)
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { hmacSha256Hex, timingSafeEqual } from '../src/hmac.js';
import { parseSites } from '../src/sites.js';
import { validateMessage, parseBody } from '../src/validator.js';
import { forwardToSite } from '../src/forwarder.js';

test('hmacSha256Hex produces a 64-char hex digest', async () => {
  const sig = await hmacSha256Hex('secret', 'hello');
  assert.equal(sig.length, 64);
  assert.match(sig, /^[0-9a-f]+$/);
});

test('hmacSha256Hex matches a known vector', async () => {
  // Known HMAC-SHA256("key","The quick brown fox jumps over the lazy dog")
  const sig = await hmacSha256Hex('key', 'The quick brown fox jumps over the lazy dog');
  assert.equal(sig, 'f7bc83f430538424b13298e6aa6fb143ef4d59a14946175997479dbc2d1a3cd8');
});

test('timingSafeEqual', () => {
  assert.equal(timingSafeEqual('abc', 'abc'), true);
  assert.equal(timingSafeEqual('abc', 'abd'), false);
  assert.equal(timingSafeEqual('abc', 'abcd'), false);
});

test('parseSites parses valid, drops invalid', () => {
  const sites = parseSites('[{"name":"a","url":"https://a/x"},{"no":"url"},{"url":"https://b/y","secret":"s"}]');
  assert.equal(sites.length, 2);
  assert.equal(sites[0].name, 'a');
  assert.equal(sites[1].secret, 's');
  assert.deepEqual(parseSites('not json'), []);
  assert.deepEqual(parseSites(undefined), []);
});

test('validateMessage JPCONFIG', () => {
  assert.equal(validateMessage({ type: 'JPCONFIG', jpId: 'O136', casId: 'IFCO', jpName: 'X' }).valid, true);
  assert.equal(validateMessage({ type: 'JPCONFIG', jpId: 'O136', casId: 'IFCO' }).valid, false);
});

test('validateMessage JPUPDATE', () => {
  assert.equal(validateMessage({ type: 'JPUPDATE', jpId: 'O136', casId: 'IFCO', jpValue: '53467', jpShared: '867' }).valid, true);
  assert.equal(validateMessage({ type: 'JPUPDATE', jpId: 'O136', casId: 'IFCO', jpValue: 'x', jpShared: '867' }).valid, false);
  assert.equal(validateMessage({ type: 'JPUPDATE', jpId: 'O136', casId: 'IFCO' }).valid, false);
});

test('validateMessage rejects junk', () => {
  assert.equal(validateMessage(null).valid, false);
  assert.equal(validateMessage({ type: 'FOO', jpId: 'x', casId: 'y' }).valid, false);
});

test('parseBody supports single + batch', () => {
  assert.deepEqual(parseBody('{"a":1}').messages.length, 1);
  assert.deepEqual(parseBody('[{"a":1},{"b":2}]').messages.length, 2);
  assert.equal(parseBody('nope').ok, false);
  assert.equal(parseBody('[]').ok, false);
});

test('forwardToSite retries on 5xx then succeeds', async () => {
  let calls = 0;
  const originalFetch = globalThis.fetch;
  globalThis.fetch = async () => {
    calls++;
    if (calls < 2) {
      return new Response('err', { status: 503 });
    }
    return new Response('{"ok":true}', { status: 200 });
  };
  try {
    const res = await forwardToSite(
      { name: 'a', url: 'https://a/x' },
      '{"type":"JPUPDATE"}',
      'secret',
      { retries: 3, backoffMs: 1, timeoutMs: 1000 }
    );
    assert.equal(res.ok, true);
    assert.equal(res.attempts, 2);
    assert.equal(calls, 2);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('forwardToSite does not retry on 4xx', async () => {
  let calls = 0;
  const originalFetch = globalThis.fetch;
  globalThis.fetch = async () => {
    calls++;
    return new Response('bad', { status: 400 });
  };
  try {
    const res = await forwardToSite(
      { name: 'a', url: 'https://a/x' },
      '{}',
      'secret',
      { retries: 3, backoffMs: 1 }
    );
    assert.equal(res.ok, false);
    assert.equal(res.status, 400);
    assert.equal(calls, 1);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('forwardToSite errors without a secret', async () => {
  const res = await forwardToSite({ name: 'a', url: 'https://a/x' }, '{}', undefined);
  assert.equal(res.ok, false);
  assert.match(res.error, /no secret/);
});
