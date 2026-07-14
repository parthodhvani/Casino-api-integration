=== Jackpot Sync ===
Contributors: rise-web
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 3.1.0
License: GPLv2 or later

Fully automated real-time jackpot sync. Receives signed jackpot data from the
Cloudflare Worker and creates/updates the Jackpot Custom Post Type and ACF
fields automatically. Everything is configured from an admin page — no file
editing required. Includes MQTT Start/Stop controls via the Worker.

== Install (3 steps) ==

1. Upload the `jackpot-sync` folder to `wp-content/plugins/` (or install the zip
   via Plugins > Add New > Upload Plugin) and click **Activate**.
   You are taken straight to the settings page.

2. On **Settings > Jackpot Sync**:
   * Paste the **Shared secret** (the same value used in your Cloudflare Worker).
   * Confirm the CPT slug and ACF field names (defaults already match the site:
     `jackpot`, `amount`, `shared_profit_amount`).
   * Choose the value format (whole units or cents) and image matching mode.
   * Save.

3. Copy the **endpoint URL** shown on the page into your Cloudflare Worker
   configuration. Done.

== How it works ==

The Worker sends signed POST requests to:

  /wp-json/jackpot/v1/update

* JPCONFIG -> creates the jackpot post if missing (matched on jpId only), stores
  hidden + legacy meta, and assigns a featured image found in the local Media
  Library (never downloaded).
* JPUPDATE -> updates the two ACF fields (`amount`, `shared_profit_amount`) and
  purges the cache.

A health check is available (no auth), handy for testing routing / WAF:

  /wp-json/jackpot/v1/ping

== Test tool ==

Tools > Jackpot Tester lets you paste a raw JPCONFIG / JPUPDATE line and process
it through the exact same pipeline as the REST endpoint — no MQTT needed.

== Security ==

Every request is verified with an HMAC-SHA256 signature (constant-time compare)
using the shared secret. Requests with a missing or wrong signature are
rejected. For maximum security you may lock the secret in wp-config.php:

  define('JACKPOT_SECRET', 'your-secret');

== Retention ==

An hourly job keeps only the newest N jackpots (default 20, configurable).

== Changelog ==

= 3.1.0 =
* MQTT Listener control panel on Settings → Jackpot Sync (Start / Stop / Refresh).
* AJAX controls proxy through the Cloudflare Worker to the Node listener.
* Worker endpoints: POST /start, POST /stop, GET /status.
* Node listener no longer connects MQTT on boot; controllable via HTTP API.
* Built-in daily schedule (06:00 start / 08:00 stop) plus cron/PM2/systemd examples.
* New setting: Cloudflare Worker URL (for MQTT control).

= 3.0.0 =
* Refactored into modular service classes (settings, logger, parser, formatter,
  image resolver, repository, cache, creator, updater, processor, REST
  controller, retention, admin).
* Removed all remote image handling; featured images are resolved from the local
  Media Library only (by jpName or jpId, configurable).
* Matching is now by jpId only.
* Added runtime metrics dashboard, health checks, activity log with structured
  context, and a Tools > Jackpot Tester page.
* Expanded cache purge (object cache, post cache, transients, LiteSpeed + ESI).
* ACF amount field default changed to `amount`.

= 2.0.0 =
* Initial admin-configured release.
