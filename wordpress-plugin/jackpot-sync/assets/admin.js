/**
 * Jackpot Sync admin — MQTT Listener controls (AJAX, no page reload).
 */
(function () {
  'use strict';

  if (typeof JackpotSyncAdmin === 'undefined') {
    return;
  }

  var cfg = JackpotSyncAdmin;

  function $(id) {
    return document.getElementById(id);
  }

  function setBusy(busy) {
    var spinner = $('jackpot-mqtt-busy');
    var buttons = [
      $('jackpot-mqtt-start'),
      $('jackpot-mqtt-stop'),
      $('jackpot-mqtt-refresh'),
    ];
    if (spinner) {
      if (busy) spinner.classList.add('is-active');
      else spinner.classList.remove('is-active');
    }
    buttons.forEach(function (btn) {
      if (btn) btn.disabled = !!busy;
    });
  }

  function showNotice(ok, message) {
    var el = $('jackpot-mqtt-notice');
    if (!el) return;
    el.hidden = false;
    el.className = 'jackpot-mqtt-notice notice ' + (ok ? 'notice-success' : 'notice-error');
    el.innerHTML = '<p></p>';
    el.querySelector('p').textContent = message || (ok ? 'OK' : cfg.i18n.error);
  }

  function applyStatus(status) {
    if (!status) return;

    var indicator = $('jackpot-mqtt-status-indicator');
    var label = $('jackpot-mqtt-status-label');
    var connection = $('jackpot-mqtt-connection');
    var lastSync = $('jackpot-mqtt-last-sync');
    var lastMessage = $('jackpot-mqtt-last-message');
    var lastConfig = $('jackpot-mqtt-last-config');
    var panel = $('jackpot-mqtt-panel');

    var running = !!status.running;
    var text = status.label || (running ? cfg.i18n.running : cfg.i18n.stopped);

    if (label) label.textContent = text;
    if (indicator) {
      indicator.classList.toggle('is-running', running);
      indicator.classList.toggle('is-stopped', !running);
    }
    if (connection) connection.textContent = status.connectionState || 'unknown';
    if (lastSync) lastSync.textContent = status.lastSyncDisplay || '—';
    if (lastMessage) lastMessage.textContent = status.lastMessageDisplay || '—';
    if (lastConfig) lastConfig.textContent = status.lastConfigDisplay || '—';
    var lastError = $('jackpot-mqtt-last-error');
    if (lastError) lastError.textContent = status.lastError ? status.lastError : '—';
    if (panel) panel.setAttribute('data-running', running ? '1' : '0');
  }

  function friendlyMessage(action, payload) {
    if (!payload) return cfg.i18n.error;
    if (payload.message) {
      var map = {
        started: 'MQTT started.',
        already_running: 'MQTT is already running.',
        stopped: 'MQTT stopped.',
        already_stopped: 'MQTT is already stopped.',
        busy: 'MQTT control is busy — try again in a moment.',
      };
      if (map[payload.message]) return map[payload.message];
      return payload.message;
    }
    if (action === 'status') return 'Status refreshed.';
    return cfg.i18n.error;
  }

  function request(action, busyLabel) {
    setBusy(true);
    if (busyLabel) showNotice(true, busyLabel);

    var body = new URLSearchParams();
    body.set('action', 'jackpot_mqtt_' + action);
    body.set('nonce', cfg.nonce);

    fetch(cfg.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      body: body.toString(),
    })
      .then(function (res) {
        return res.json().then(function (json) {
          return { httpOk: res.ok, json: json };
        });
      })
      .then(function (result) {
        var json = result.json || {};
        var data = json.data || {};
        var ok = !!json.success;

        if (data.status) applyStatus(data.status);

        var msg = friendlyMessage(action, data);
        if (!ok && data.message) msg = data.message;
        showNotice(ok, msg);
      })
      .catch(function () {
        showNotice(false, cfg.i18n.error);
      })
      .finally(function () {
        setBusy(false);
      });
  }

  function bind() {
    var start = $('jackpot-mqtt-start');
    var stop = $('jackpot-mqtt-stop');
    var refresh = $('jackpot-mqtt-refresh');

    if (start) {
      start.addEventListener('click', function () {
        request('start', cfg.i18n.starting);
      });
    }
    if (stop) {
      stop.addEventListener('click', function () {
        request('stop', cfg.i18n.stopping);
      });
    }
    if (refresh) {
      refresh.addEventListener('click', function () {
        request('status', cfg.i18n.refreshing);
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
