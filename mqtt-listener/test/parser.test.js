/**
 * Parser tests. Compatible with Node 14 test runner.
 */
'use strict';

const { parseMessage } = require('../src/parser');

test('parses JPCONFIG', function () {
  const r = parseMessage('JPCONFIG;O136;2;11;Huff n Puff Mystery LVL2;;IFCO');
  assert.strictEqual(r.type, 'JPCONFIG');
  assert.strictEqual(r.jpId, 'O136');
  assert.strictEqual(r.jpName, 'Huff n Puff Mystery LVL2');
  assert.strictEqual(r.casId, 'IFCO');
});

test('parses JPUPDATE', function () {
  const r = parseMessage('JPUPDATE;O136;2;0;217;53467;867;0;IFCO');
  assert.strictEqual(r.type, 'JPUPDATE');
  assert.strictEqual(r.jpId, 'O136');
  assert.strictEqual(r.jpValue, '53467');
  assert.strictEqual(r.jpShared, '867');
  assert.strictEqual(r.casId, 'IFCO');
});

test('trims surrounding whitespace', function () {
  const r = parseMessage('  JPUPDATE;O136;2;0;217;53467;867;0;IFCO  ');
  assert.strictEqual(r.jpId, 'O136');
  assert.strictEqual(r.casId, 'IFCO');
});

test('rejects unknown type', function () {
  assert.strictEqual(parseMessage('FOO;1;2;3'), null);
});

test('rejects empty / malformed', function () {
  assert.strictEqual(parseMessage(''), null);
  assert.strictEqual(parseMessage('JPUPDATE;O136'), null);
  assert.strictEqual(parseMessage(null), null);
});

test('rejects JPCONFIG missing jpId or casId', function () {
  assert.strictEqual(parseMessage('JPCONFIG;;2;11;Name;;IFCO'), null);
  assert.strictEqual(parseMessage('JPCONFIG;O136;2;11;Name;;'), null);
});
