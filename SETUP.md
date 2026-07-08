# Setup Guide — step by step

This guide takes you from nothing to a working pipeline on **one site**
(`casinoberck.fr`) for testing, then rolling out to all three.

Do the parts in order.

---

## Part 0 — Generate your two secrets

You need two random strings. Run this twice and save both outputs:

```bash
openssl rand -hex 32   # -> use as JACKPOT_SECRET  (Worker + WordPress)
openssl rand -hex 32   # -> use as LISTENER_SECRET (Worker + Listener)
```

- **JACKPOT_SECRET** — shared between the Worker and WordPress (signs the payload).
- **LISTENER_SECRET** — shared between the listener and the Worker (proves the caller is your listener).

---

## Part 1 — WordPress plugin (do this first, on site #1)

The plugin is configured from an admin page — **no `wp-config.php` editing needed.**

### 1a. Get the zip

Download `wordpress-plugin/jackpot-sync.zip` from the repo. On GitHub, open the
file and click **“Download raw file”** (do not copy/paste the contents — it's a
binary). Or build it yourself:

```bash
cd wordpress-plugin
zip -rX jackpot-sync.zip jackpot-sync
```

### 1b. Install

1. **wp-admin → Plugins → Add New → Upload Plugin**.
2. Choose `jackpot-sync.zip` → **Install Now** → **Activate**.
3. You are taken to **Settings → Jackpot Sync** automatically.

> If you see *“No valid plugins were found”*, the zip was corrupted during
> download (see Troubleshooting at the end). Re-download with “Download raw
> file”, or upload the `jackpot-sync` folder directly via SFTP to
> `wp-content/plugins/` instead.

### 1c. Configure (on the settings page)

1. Paste your **shared secret** (the `JACKPOT_SECRET` value).
2. Confirm the CPT slug (`jackpot`) and ACF field names
   (`jackpot_amount`, `shared_profit_amount`) — defaults already match this site.
3. Choose the **value format** (whole euros vs cents).
4. **Save**. Check the green status boxes: secret set, ACF active, CPT exists.

### 1d. Verify the route is live

Click the health-check link on the settings page, or:

```bash
curl https://casinoberck.fr/wp-json/jackpot/v1/ping
# -> {"ok":true,"plugin":"jackpot-sync","secret":true}
```

If this is blocked or returns a Combell error page, open a Combell support
ticket now to allowlist the route (see Part 5).

---

## Part 2 — Cloudflare Worker

1. Install and log in:

   ```bash
   cd cloudflare-worker
   npm install
   npx wrangler login
   ```

2. Set the two secrets (paste the values from Part 0):

   ```bash
   npx wrangler secret put JACKPOT_SECRET
   npx wrangler secret put LISTENER_SECRET
   ```

3. Set the target site(s) in `wrangler.toml` (start with one):

   ```toml
   [vars]
   WP_SITES = '[{"name":"berck","url":"https://casinoberck.fr/wp-json/jackpot/v1/update"}]'
   ```

4. Deploy:

   ```bash
   npm run deploy
   ```

   Copy the printed URL, e.g. `https://jackpot-worker.<sub>.workers.dev`.

5. (Optional) Open a live log stream in a separate terminal:

   ```bash
   npm run tail
   ```

---

## Part 3 — MQTT listener (laptop for testing)

1. Install and configure:

   ```bash
   cd mqtt-listener
   npm install
   cp .env.example .env
   ```

2. Edit `.env`:

   ```env
   MQTT_HOST=699e47e0f3cc47a58a4da1fbeff8f089.s1.eu.hivemq.cloud
   MQTT_PORT=8883
   MQTT_USERNAME=drgt.drTv
   MQTT_PASSWORD=drTv1234
   MQTT_TOPIC=/jp/gent
   WORKER_URL=https://jackpot-worker.<sub>.workers.dev
   LISTENER_SECRET=PASTE_YOUR_LISTENER_SECRET
   ```

3. Run it:

   ```bash
   npm start
   ```

   Expected:

   ```
   [mqtt] connected to mqtts://...hivemq.cloud:8883
   [mqtt] subscribed to /jp/gent
   [msg] /jp/gent => JPUPDATE;O136;2;0;217;53467;867;0;IFCO
   [worker] 200 {"ok":true,...}
   ```

---

## Part 4 — End-to-end verification

**Don't want to wait for a live MQTT message?** Simulate one to test the
Worker → WordPress path instantly:

```bash
WORKER_URL="https://jackpot-worker.<sub>.workers.dev" \
LISTENER_SECRET="your-listener-secret" \
node tools/simulate-message.js config   # creates the jackpot post

WORKER_URL="https://jackpot-worker.<sub>.workers.dev" \
LISTENER_SECRET="your-listener-secret" \
node tools/simulate-message.js update    # updates the values
```

Watch all layers in this order when a (real or simulated) message arrives:

1. **Listener terminal** → `[msg] ...` then `[worker] 200`.
2. **Worker logs** (`npm run tail`) → shows the incoming POST + per-site results.
3. **WordPress** → `wp-admin/edit.php?post_type=jackpot`:
   - First `JPCONFIG` for a jackpot creates a post.
   - `JPUPDATE` changes `jackpot_amount` and `shared_profit_amount`.
4. **Front-end carousel** → values update after cache purge.

### Verify the euros-vs-cents question (important)

Take one live `jpValue` and compare to the real displayed jackpot:

- Real display `€534.67` and payload `53467` → it's **cents** → choose **Cents** on the settings page.
- Real display `€53,467` and payload `53467` → it's **euros** → choose **Whole euros**.

---

## Part 5 — Combell WAF (do before you rely on it)

If requests are blocked (403 / HTML error page instead of JSON):

- Ask Combell support to **allowlist** the route `…/wp-json/jackpot/v1/update`
  for the Cloudflare Worker's outbound IP ranges.
- Confirm the `X-Signature` custom header is **not stripped** by their WAF.
- Get a direct support contact so you can unblock quickly during testing.

---

## Part 6 — Retention

The plugin schedules an hourly cron that keeps the newest N jackpots
(by modified date) and deletes older ones. Set the limit under
**Settings → Jackpot Sync → Keep newest** (default 20).

> If WP-Cron is unreliable on the host (low traffic), disable it and use a real
> Combell cron hitting `wp-cron.php` on a schedule:
> add `define('DISABLE_WP_CRON', true);` and configure the server cron.

---

## Part 7 — Roll out to sites 2 and 3

1. Repeat **Part 1** on each remaining site (same zip, same shared secret pasted on each settings page).
2. Add their URLs to `WP_SITES` in `wrangler.toml`:

   ```toml
   WP_SITES = '[
     {"name":"berck","url":"https://siteA/wp-json/jackpot/v1/update"},
     {"name":"oostende","url":"https://siteB/wp-json/jackpot/v1/update"},
     {"name":"dinant","url":"https://siteC/wp-json/jackpot/v1/update"}
   ]'
   ```

3. `npm run deploy` again. The one Worker now fans out to all three.

> Want per-site secrets instead of one shared secret? Add `"secret":"..."` to a
> site in `WP_SITES` and use that same value in that site's `wp-config.php`.

---

## Part 8 — Production (move the listener off your laptop)

Deploy the listener to an always-on host (see `mqtt-listener/README.md` for
Render steps). Set the same env vars there. Consider upgrading to Cloudflare
Workers Paid for headroom and better log retention.

### Recommended monitoring

- A free "dead man's switch" (e.g. Healthchecks.io) pinged by the listener on
  each successful message — alerts you if the feed goes silent.

---

## Troubleshooting

### “The package could not be installed. No valid plugins were found.”

This means WordPress received a broken or wrongly-structured zip. Fixes, in order:

1. **Re-download correctly.** On GitHub, open `jackpot-sync.zip` and use the
   **“Download raw file”** button. Don't right-click the raw view and “Save as”,
   and don't `git pull` the zip on a machine with `core.autocrlf=true` — that can
   corrupt binaries. (This repo now marks `*.zip` as binary via `.gitattributes`
   to prevent that.)
2. **Check the structure.** The zip must contain a single top-level folder
   `jackpot-sync/` with `jackpot-sync.php` directly inside it. Verify with:

   ```bash
   unzip -l jackpot-sync.zip
   ```

3. **Rebuild it yourself** (guaranteed clean):

   ```bash
   cd wordpress-plugin
   zip -rX jackpot-sync.zip jackpot-sync
   ```

4. **Skip the zip entirely.** Upload the `jackpot-sync` **folder** directly to
   `wp-content/plugins/` via SFTP / the Combell file manager, then activate it
   from **Plugins**. This always works.

### The endpoint returns 401 / “signature mismatch”

The secret on the settings page must be **exactly** the Worker's `JACKPOT_SECRET`
(no extra spaces, full length). Re-paste both.

### `/ping` returns a Combell/HTML error instead of JSON

The WAF is blocking the route — see Part 5.
