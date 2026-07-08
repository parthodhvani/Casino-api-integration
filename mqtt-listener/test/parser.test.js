/**
 * Minimal parser tests. Run with: npm test
 */
const assert = require('assert');
const { parseMessage } = require('../src/parser');

let passed = 0;
function check(name, fn) {
  try {
    fn();
    passed++;
    console.log(`  ok  - ${name}`);
  } catch (err) {
    console.error(`  FAIL - ${name}: ${err.message}`);
    process.exitCode = 1;
  }
}

check('parses JPCONFIG', () => {
  const r = parseMessage('JPCONFIG;O136;2;11;Huff n Puff Mystery LVL2;;IFCO');
  assert.strictEqual(r.type, 'JPCONFIG');
  assert.strictEqual(r.jpId, 'O136');
  assert.strictEqual(r.jpName, 'Huff n Puff Mystery LVL2');
  assert.strictEqual(r.casId, 'IFCO');
});

check('parses JPUPDATE', () => {
  const r = parseMessage('JPUPDATE;O136;2;0;217;53467;867;0;IFCO');
  assert.strictEqual(r.type, 'JPUPDATE');
  assert.strictEqual(r.jpId, 'O136');
  assert.strictEqual(r.jpValue, '53467');
  assert.strictEqual(r.jpShared, '867');
  assert.strictEqual(r.casId, 'IFCO');
});

check('rejects unknown type', () => {
  assert.strictEqual(parseMessage('FOO;1;2;3'), null);
});

check('rejects empty / malformed', () => {
  assert.strictEqual(parseMessage(''), null);
  assert.strictEqual(parseMessage('JPUPDATE;O136'), null);
});

console.log(`\n${passed} checks passed.`);
