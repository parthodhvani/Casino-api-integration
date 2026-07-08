# Install — WordPress plugin

Three ways to install `jackpot-sync`. All end at **Settings → Jackpot Sync**.

## Prerequisites

- WordPress 5.8+ and PHP 7.4+
- ACF (Pro) active — the plugin writes the `amount` and `shared_profit_amount`
  ACF fields (it falls back to post meta if ACF is absent, but the carousel
  expects ACF).
- The Jackpot Custom Post Type registered (default slug `jackpot`).

## Option A — Upload the ZIP (recommended)

1. Build (or download) the production ZIP:
   ```bash
   cd wordpress-plugin
   zip -rX jackpot-sync-v3.0.0.zip jackpot-sync
   ```
   On GitHub, use **“Download raw file”** for the committed ZIP (it is a binary;
   `.gitattributes` marks `*.zip` as binary to prevent corruption).
2. **wp-admin → Plugins → Add New → Upload Plugin** → choose the ZIP →
   **Install Now** → **Activate**.
3. You are redirected to **Settings → Jackpot Sync**.

The ZIP must contain a single top-level folder `jackpot-sync/` with
`jackpot-sync.php` directly inside it. Verify with `unzip -l jackpot-sync-v3.0.0.zip`.

## Option B — SFTP the folder

Upload the `wordpress-plugin/jackpot-sync` **folder** to `wp-content/plugins/`,
then activate it under **Plugins**. This always works even if ZIP upload is
blocked.

## Option C — WP-CLI

```bash
wp plugin install /path/to/jackpot-sync-v3.0.0.zip --activate
```

## Configure

On **Settings → Jackpot Sync**:

1. Paste the **Shared secret** (must equal the Worker's `JACKPOT_SECRET`), or
   lock it in `wp-config.php`:
   ```php
   define('JACKPOT_SECRET', 'your-secret');
   ```
2. Confirm the **CPT slug** (`jackpot`) and **ACF field names**
   (`amount`, `shared_profit_amount`).
3. Choose the **value format** (whole units vs cents).
4. Choose the **image matching mode** (`jpName` default, or `jpId`).
5. Set **Keep newest** (retention, default 20).
6. **Save** and confirm the green health checks.

## Verify

```bash
curl https://YOUR-SITE/wp-json/jackpot/v1/ping
# -> {"ok":true,"plugin":"jackpot-sync","version":"3.0.0","secret":true,"acf":true,"cpt":true,...}
```

## Uninstall

Deleting the plugin removes its options (`jackpot_sync_settings`,
`jackpot_sync_log`, `jackpot_sync_stats`) and the retention cron. Jackpot posts
are intentionally left in place.
