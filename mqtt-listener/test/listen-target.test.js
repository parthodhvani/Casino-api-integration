/**
 * Listen-target resolution tests (cPanel / LiteSpeed).
 */
'use strict';

const path = require('path');

test('resolveListenTarget defaults to litespeed-auto', function () {
  const saved = {
    PORT: process.env.PORT,
    BIND_MODE: process.env.BIND_MODE,
    CONTROL_PORT: process.env.CONTROL_PORT,
    CONTROL_HOST: process.env.CONTROL_HOST,
  };

  delete process.env.PORT;
  delete process.env.BIND_MODE;
  delete process.env.CONTROL_PORT;
  delete process.env.CONTROL_HOST;

  // Inline copy of resolve logic expectations via requiring module after env set
  // Use a fresh eval of the function from the file by requiring with all required env.
  process.env.MQTT_TOPIC = process.env.MQTT_TOPIC || '/jp/test';
  process.env.LISTENER_SECRET = process.env.LISTENER_SECRET || 'test-listener';
  process.env.JACKPOT_SECRET = process.env.JACKPOT_SECRET || 'test-jackpot';
  process.env.WP_SITES =
    process.env.WP_SITES ||
    JSON.stringify([{ name: 't', url: 'https://example.com/wp-json/jackpot/v1/update' }]);
  process.env.MQTT_HOST = process.env.MQTT_HOST || 'example.hivemq.cloud';
  process.env.MQTT_USERNAME = process.env.MQTT_USERNAME || 'u';
  process.env.MQTT_PASSWORD = process.env.MQTT_PASSWORD || 'p';

  const configPath = path.join(__dirname, '../src/config.js');
  delete require.cache[require.resolve(configPath)];
  const config = require(configPath);
  assert.strictEqual(config.control.mode, 'litespeed-auto');
  assert.strictEqual(config.control.port, null);
  assert.strictEqual(config.control.host, null);

  const resolve = config.resolveListenTarget;
  assert.strictEqual(resolve().mode, 'litespeed-auto');

  process.env.PORT = '/tmp/lsnode.sock';
  assert.strictEqual(resolve().mode, 'unix-socket');
  assert.strictEqual(resolve().port, '/tmp/lsnode.sock');

  process.env.BIND_MODE = 'explicit';
  delete process.env.PORT;
  process.env.CONTROL_PORT = '3099';
  const explicit = resolve();
  assert.strictEqual(explicit.mode, 'fallback-control-port');
  assert.strictEqual(explicit.port, 3099);

  // restore
  Object.keys(saved).forEach(function (k) {
    if (saved[k] === undefined) delete process.env[k];
    else process.env[k] = saved[k];
  });
});
