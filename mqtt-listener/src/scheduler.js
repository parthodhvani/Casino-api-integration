/**
 * Daily MQTT connect / disconnect scheduler.
 * Compatible with Node.js 14+ (no Object.fromEntries dependency path issues).
 */

'use strict';

const logger = require('./logger');

/**
 * @param {object} options
 * @param {object} options.config
 * @param {object} options.mqttManager
 * @returns {{ stop: function(): void }}
 */
function startScheduler(options) {
  const config = options.config;
  const mqttManager = options.mqttManager;
  const schedule = config.schedule;
  if (!schedule.enabled) {
    logger.info('scheduler disabled');
    return {
      stop: function () {},
    };
  }

  let lastStartKey = '';
  let lastStopKey = '';
  let timer = null;

  const tick = async function () {
    const now = zonedParts(new Date(), schedule.timezone);
    const hm = pad(now.hour) + ':' + pad(now.minute);
    const dayKey = now.year + '-' + pad(now.month) + '-' + pad(now.day);

    if (hm === schedule.startTime) {
      const key = dayKey + '-start';
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
      const key = dayKey + '-stop';
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

  timer = setInterval(function () {
    tick().catch(function (err) {
      logger.error('scheduler tick failed', { error: err.message });
    });
  }, 20 * 1000);
  if (typeof timer.unref === 'function') timer.unref();

  tick().catch(function (err) {
    logger.error('scheduler initial tick failed', { error: err.message });
  });

  logger.info('scheduler enabled', {
    start: schedule.startTime,
    stop: schedule.stopTime,
    timezone: schedule.timezone,
  });

  return {
    stop: function () {
      if (timer) clearInterval(timer);
      timer = null;
    },
  };
}

/**
 * Wall-clock parts in a specific IANA timezone.
 * Uses formatToParts (available in Node 14 ICU builds).
 *
 * @param {Date} date
 * @param {string} timeZone
 * @returns {{year:number,month:number,day:number,hour:number,minute:number}}
 */
function zonedParts(date, timeZone) {
  try {
    const fmt = new Intl.DateTimeFormat('en-GB', {
      timeZone: timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    });
    const partsArr = fmt.formatToParts(date);
    const parts = {};
    for (let i = 0; i < partsArr.length; i++) {
      parts[partsArr[i].type] = partsArr[i].value;
    }
    // Some Node 14 ICU builds return "24" for midnight — normalize to 0.
    let hour = Number(parts.hour);
    if (hour === 24) hour = 0;
    return {
      year: Number(parts.year),
      month: Number(parts.month),
      day: Number(parts.day),
      hour: hour,
      minute: Number(parts.minute),
    };
  } catch (err) {
    logger.warn('invalid SCHEDULE_TZ, using local time', {
      timeZone: timeZone,
      error: err.message,
    });
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
  const s = String(n);
  return s.length < 2 ? '0' + s : s;
}

module.exports = { startScheduler: startScheduler, zonedParts: zonedParts };
