=== Jackpot Sync ===
Contributors: rise-web
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later

Receives real-time jackpot data from the Cloudflare Worker and writes it to the
Jackpot Custom Post Type and ACF fields. Everything is configured from an admin
page — no file editing required.

== Install (3 steps) ==

1. Upload the `jackpot-sync` folder to `wp-content/plugins/` (or install the zip
   via Plugins > Add New > Upload Plugin) and click **Activate**.
   You are taken straight to the settings page.

2. On **Settings > Jackpot Sync**:
   * Paste the **Shared secret** (the same value used in your Cloudflare Worker).
   * Confirm the CPT slug and ACF field names (defaults already match the site:
     `jackpot`, `jackpot_amount`, `shared_profit_amount`).
   * Choose the value format (whole euros or cents).
   * Save.

3. Copy the **endpoint URL** shown on the page into your Cloudflare Worker
   configuration. Done.

== How it works ==

The Worker sends signed POST requests to:

  /wp-json/jackpot/v1/update

* JPCONFIG -> creates the jackpot post if missing (matched on jpId + casId).
* JPUPDATE -> updates the two ACF fields and purges the cache.

A health check is available (no auth), handy for testing routing / WAF:

  /wp-json/jackpot/v1/ping

== Status page ==

The settings page shows green/red checks for: secret set, ACF active, and the
CPT existing — so you can confirm the install at a glance. It also shows a live
activity log of the most recent events.

== Security ==

Every request is verified with an HMAC-SHA256 signature using the shared secret.
Requests with a missing or wrong signature are rejected. For maximum security you
may lock the secret in wp-config.php instead of the settings page:

  define('JACKPOT_SECRET', 'your-secret');

== Retention ==

An hourly job keeps only the newest N jackpots (default 20, configurable).
