/**
 * Loads and validates configuration from environment variables.
 * Works the same on a laptop (via .env) and in production (host env vars).
 */

require('dotenv').config();

function required(name) {
  const value = process.env[name];
  if (!value) {
    console.error(`[fatal] Missing required env var: ${name}`);
    process.exit(1);
  }
  return value;
}

const config = {
  mqtt: {
    host: required('MQTT_HOST'),
    port: process.env.MQTT_PORT || '8883',
    username: required('MQTT_USERNAME'),
    password: required('MQTT_PASSWORD'),
    topic: required('MQTT_TOPIC'),
  },
  worker: {
    url: required('WORKER_URL'),
    listenerSecret: required('LISTENER_SECRET'),
  },
};

config.mqtt.brokerUrl = `mqtts://${config.mqtt.host}:${config.mqtt.port}`;

module.exports = config;
