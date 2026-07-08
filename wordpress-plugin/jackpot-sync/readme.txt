=== Jackpot Sync ===
Contributors: rise-web
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Receives real-time jackpot data from the Cloudflare Worker and writes it to the
Jackpot Custom Post Type and ACF fields.

== Description ==

This plugin exposes a signed REST endpoint that the Cloudflare Worker calls:

  POST /wp-json/jackpot/v1/update

It handles two message types:

* JPCONFIG - creates the jackpot post (matched on jpId + casId) if missing.
* JPUPDATE - updates the ACF fields `jackpot_amount` and `shared_profit_amount`.

A health check is also available (no auth):

  GET /wp-json/jackpot/v1/ping

== Installation ==

1. Copy the `jackpot-sync` folder into `wp-content/plugins/`.
2. Add the shared secret to `wp-config.php`:

       define('JACKPOT_SECRET', 'paste-the-same-secret-used-in-the-worker');

3. (Optional overrides in wp-config.php)

       define('JACKPOT_CPT', 'jackpot');       // CPT slug (default: jackpot)
       define('JACKPOT_MAX_KEEP', 20);         // retention limit (default: 20)
       define('JACKPOT_VALUE_DIVISOR', 1);     // set to 100 if values arrive in cents

4. Activate the plugin in wp-admin > Plugins.

== ACF fields expected ==

* jackpot_amount
* shared_profit_amount

== Unique key ==

Posts are matched on two post-meta values: `jp_id` and `cas_id`.
These are stored automatically on the first JPCONFIG for each jackpot.
