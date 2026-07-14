/**
 * Minimal test runner compatible with Node.js 14+ (no node:test).
 * Usage: node test/run-tests.js
 */

'use strict';

const path = require('path');
const fs = require('fs');
const assert = require('assert');

let passed = 0;
let failed = 0;
const queue = [];

function test(name, fn) {
  queue.push({ name: name, fn: fn });
}

async function run() {
  const files = fs
    .readdirSync(__dirname)
    .filter(function (f) {
      return f.endsWith('.test.js');
    })
    .sort();

  // Expose a tiny harness to each test file via global.
  global.test = test;
  global.assert = assert;

  for (let i = 0; i < files.length; i++) {
    const file = path.join(__dirname, files[i]);
    // Clear require cache so re-runs are fresh if needed.
    delete require.cache[require.resolve(file)];
    require(file);
  }

  for (let i = 0; i < queue.length; i++) {
    const item = queue[i];
    try {
      const maybePromise = item.fn();
      if (maybePromise && typeof maybePromise.then === 'function') {
        await maybePromise;
      }
      passed++;
      console.log('  ok   - ' + item.name);
    } catch (err) {
      failed++;
      console.log('  FAIL - ' + item.name);
      console.log('         ' + (err && err.stack ? err.stack : err));
    }
  }

  console.log('----------------------------------------');
  console.log('Passed: ' + passed + ', Failed: ' + failed);
  process.exit(failed === 0 ? 0 : 1);
}

run().catch(function (err) {
  console.error(err);
  process.exit(1);
});
