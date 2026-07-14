/**
 * Daily MQTT connect / disconnect scheduler.
 *
 * Keeps the Node process alive; only toggles the MQTT connection via
 * startMQTT() / stopMQTT(). Default window: 06:00 → 08:00 local time.
 *
 * External cron / PM2 / systemd can also hit POST /start and POST /stop —
 * this built-in scheduler is optional (SCHEDULE_ENABLED=true by default).
 */

const logger = require('./logger');

/**
 * @param {object} options
 * @param {object} options.config
 * @param {object} options.mqttManager
 * @returns {{ stop: () => void }}
 */
function startScheduler({ config, mqttManager }) {
  const schedule = config.schedule;
  if (!schedule.enabled) {
    logger.info('scheduler disabled');
    return { stop: () => {} };
  }

  let lastStartKey = '';
  let lastStopKey = '';
  let timer = null;

  const tick = async () => {
    const now = zonedParts(new Date(), schedule.timezone);
    const hm = `${pad(now.hour)}:${pad(now.minute)}`;
    const dayKey = `${now.year}-${pad(now.month)}-${pad(now.day)}`;

    if (hm === schedule.startTime) {
      const key = `${dayKey}-start`;
      if (key !== lastStartKey) {
        lastStartKey = key;
        logger.info('scheduler start window', { time: hm, tz: schedule.timezone });
        try {
          const result = await mqttManager.startMQTT(config);
          logger.info('scheduler startMQTT result', result);
        } catch (err) {
          logger.error('scheduler startMQTT failed', { error: err.message });
        }
      }
    }

    if (hm === schedule.stopTime) {
      const key = `${dayKey}-stop`;
      if (key !== lastStopKey) {
        lastStopKey = key;
        logger.info('scheduler stop window', { time: hm, tz: schedule.timezone });
        try {
          const result = await mqttManager.stopMQTT();
          logger.info('scheduler stopMQTT result', result);
        } catch (err) {
          logger.error('scheduler stopMQTT failed', { error: err.message });
        }
      }
    }
  };

  // Check every 20s so we don't miss the target minute.
  timer = setInterval(tick, 20 * 1000);
  if (typeof timer.unref === 'function') timer.unref();

  // Run once immediately so a restart inside the window can catch up.
  tick().catch((err) => logger.error('scheduler initial tick failed', { error: err.message }));

  logger.info('scheduler enabled', {
    start: schedule.startTime,
    stop: schedule.stopTime,
    timezone: schedule.timezone,
  });

  return {
    stop() {
      if (timer) clearInterval(timer);
      timer = null;
    },
  };
}

/**
 * Wall-clock parts in a specific IANA timezone.
 *
 * @param {Date} date
 * @param {string} timeZone
 * @returns {{year:number,month:number,day:number,hour:number,minute:number}}
 */
function zonedParts(date, timeZone) {
  try {
    const fmt = new Intl.DateTimeFormat('en-GB', {
      timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    });
    const parts = Object.fromEntries(fmt.formatToParts(date).map((p) => [p.type, p.value]));
    return {
      year: Number(parts.year),
      month: Number(parts.month),
      day: Number(parts.day),
      hour: Number(parts.hour),
      minute: Number(parts.minute),
    };
  } catch (err) {
    // Fallback to local machine time if TZ is invalid.
    logger.warn('invalid SCHEDULE_TZ, using local time', { timeZone, error: err.message });
    return {
      year: date.getFullYear(),
      month: date.getMonth() + 1,
      day: date.getDate(),
      hour: date.getHours(),
      minute: date.getMinutes(),
    };
  }
}

/**
 * @param {number} n
 * @returns {string}
 */
function pad(n) {
  return String(n).padStart(2, '0');
}

module.exports = { startScheduler, zonedParts };
