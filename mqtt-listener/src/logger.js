/**
 * Tiny structured logger. Emits single-line JSON so logs are easy to grep and
 * to ship to a log aggregator in production.
 */

function emit(level, message, context) {
  const line = { level, ts: new Date().toISOString(), message };
  if (context && Object.keys(context).length > 0) {
    Object.assign(line, context);
  }
  const method = level === 'error' ? 'error' : level === 'warn' ? 'warn' : 'log';
  console[method](JSON.stringify(line));
}

module.exports = {
  info: (message, context) => emit('info', message, context),
  warn: (message, context) => emit('warn', message, context),
  error: (message, context) => emit('error', message, context),
};
