=== Jackpot Sync ===
Contributors: riseweb
Tags: jackpot, mqtt, acf, casino
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 3.2.0
License: GPLv2 or later

Receives signed jackpot updates from the Node MQTT listener and syncs Jackpot CPT + ACF fields.

== Description ==

Fully automated real-time jackpot sync. The Node MQTT listener POSTs signed JSON
to this plugin. Configure under Settings > Jackpot Sync.

No file editing required. Includes MQTT Start/Stop controls that talk directly
to the Node listener control API.

== Installation ==

1. Upload the `jackpot-sync` folder to `/wp-content/plugins/`
2. Activate the plugin
3. Go to Settings → Jackpot Sync:
   * Paste the **Shared secret** (same value as Node `JACKPOT_SECRET`).
   * Paste the **MQTT Listener URL** (e.g. `https://mqtt.waayup.be`).
4. Copy the **endpoint URL** shown on the page into the Node listener `WP_SITES` env var.

== REST ==

The Node listener sends signed POST requests to:

`POST /wp-json/jackpot/v1/update`

Header: `X-Signature: HMAC-SHA256(secret, raw_body)`

== Changelog ==

= 3.2.0 =
* Removed Cloudflare Worker dependency.
* MQTT Start/Stop/Status talks directly to the Node listener.
* Setting renamed: Worker URL → MQTT Listener URL (`listener_url`).

= 3.1.0 =
* AJAX controls for MQTT start/stop/status.
* Built-in daily schedule on the Node listener.
