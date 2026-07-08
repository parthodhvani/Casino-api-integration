/**
 * Parser tests. Run with: npm test  (node --test)
 */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { parseMessage } = require('../src/parser');

test('parses JPCONFIG', () => {
  const r = parseMessage('JPCONFIG;O136;2;11;Huff n Puff Mystery LVL2;;IFCO');
  assert.equal(r.type, 'JPCONFIG');
  assert.equal(r.jpId, 'O136');
  assert.equal(r.jpName, 'Huff n Puff Mystery LVL2');
  assert.equal(r.casId, 'IFCO');
});

test('parses JPUPDATE', () => {
  const r = parseMessage('JPUPDATE;O136;2;0;217;53467;867;0;IFCO');
  assert.equal(r.type, 'JPUPDATE');
  assert.equal(r.jpId, 'O136');
  assert.equal(r.jpValue, '53467');
  assert.equal(r.jpShared, '867');
  assert.equal(r.casId, 'IFCO');
});

test('trims surrounding whitespace', () => {
  const r = parseMessage('  JPUPDATE;O136;2;0;217;53467;867;0;IFCO  ');
  assert.equal(r.jpId, 'O136');
  assert.equal(r.casId, 'IFCO');
});

test('rejects unknown type', () => {
  assert.equal(parseMessage('FOO;1;2;3'), null);
});

test('rejects empty / malformed', () => {
  assert.equal(parseMessage(''), null);
  assert.equal(parseMessage('JPUPDATE;O136'), null);
  assert.equal(parseMessage(null), null);
});

test('rejects JPCONFIG missing jpId or casId', () => {
  assert.equal(parseMessage('JPCONFIG;;2;11;Name;;IFCO'), null);
  assert.equal(parseMessage('JPCONFIG;O136;2;11;Name;;'), null);
});
