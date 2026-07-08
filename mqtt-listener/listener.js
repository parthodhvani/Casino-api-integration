/**
 * MQTT Listener
 *
 * Holds a persistent MQTT connection to the HiveMQ broker, parses the
 * semicolon-separated DRGT messages, and forwards a normalized JSON payload
 * to the Cloudflare Worker.
 *
 * Run locally for testing, or on an always-on host (Render/Fly/VPS) for production.
 * Configuration is read from environment variables (see .env.example).
 */

require('dotenv').config();
const mqtt = require('mqtt');

const {
  MQTT_HOST,
  MQTT_PORT = '8883',
  MQTT_USERNAME,
  MQTT_PASSWORD,
  MQTT_TOPIC,
  WORKER_URL,
  LISTENER_SECRET,
} = process.env;

function requireEnv(name, value) {
  if (!value) {
    console.error(`[fatal] Missing required env var: ${name}`);
    process.exit(1);
  }
}

requireEnv('MQTT_HOST', MQTT_HOST);
requireEnv('MQTT_USERNAME', MQTT_USERNAME);
requireEnv('MQTT_PASSWORD', MQTT_PASSWORD);
requireEnv('MQTT_TOPIC', MQTT_TOPIC);
requireEnv('WORKER_URL', WORKER_URL);
requireEnv('LISTENER_SECRET', LISTENER_SECRET);

const brokerUrl = `mqtts://${MQTT_HOST}:${MQTT_PORT}`;

const client = mqtt.connect(brokerUrl, {
  username: MQTT_USERNAME,
  password: MQTT_PASSWORD,
  reconnectPeriod: 5000, // auto-reconnect every 5s on drop
  connectTimeout: 30000,
});

client.on('connect', () => {
  console.log(`[mqtt] connected to ${brokerUrl}`);
  client.subscribe(MQTT_TOPIC, (err) => {
    if (err) {
      console.error('[mqtt] subscribe error:', err.message);
    } else {
      console.log(`[mqtt] subscribed to ${MQTT_TOPIC}`);
    }
  });
});

client.on('reconnect', () => console.log('[mqtt] reconnecting...'));
client.on('close', () => console.log('[mqtt] connection closed'));
client.on('error', (err) => console.error('[mqtt] error:', err.message));

client.on('message', async (topic, message) => {
  const raw = message.toString();
  console.log(`[msg] ${topic} => ${raw}`);

  const payload = parseMessage(raw);
  if (!payload) {
    console.log('[msg] skipped (unknown or malformed)');
    return;
  }

  await forwardToWorker(payload);
});

/**
 * Parse the semicolon-separated DRGT message.
 *
 *   JPCONFIG;<jpId>;<level>;<jpType>;<jpName>;<prizeName>;<CasID>
 *   JPUPDATE;<jpId>;<level>;<_>;<_>;<jpValue>;<jpShared>;<_>;<CasID>
 */
function parseMessage(raw) {
  const parts = raw.split(';');
  const type = parts[0];

  if (type === 'JPCONFIG') {
    return {
      type,
      jpId: parts[1],
      level: parts[2],
      jpType: parts[3],
      jpName: parts[4],
      prizeName: parts[5],
      casId: parts[6],
    };
  }

  if (type === 'JPUPDATE') {
    return {
      type,
      jpId: parts[1],
      level: parts[2],
      jpValue: parts[5],
      jpShared: parts[6],
      casId: parts[8],
    };
  }

  return null;
}

async function forwardToWorker(payload) {
  try {
    const res = await fetch(WORKER_URL, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'x-listener-secret': LISTENER_SECRET,
      },
      body: JSON.stringify(payload),
    });
    const text = await res.text();
    console.log(`[worker] ${res.status} ${text.slice(0, 200)}`);
  } catch (err) {
    console.error('[worker] forward failed:', err.message);
  }
}

// Graceful shutdown
process.on('SIGINT', () => {
  console.log('\n[shutdown] closing MQTT connection...');
  client.end(true, () => process.exit(0));
});
