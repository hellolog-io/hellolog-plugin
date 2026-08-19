=== helloLOG ===
Contributors:      hellologio
Tags:              activity log, audit log, security, monitoring, woocommerce
Requires at least: 6.4
Tested up to:      6.7
Requires PHP:      8.0
Stable tag:        0.4.3
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Lightweight WordPress activity log by hellolog.io. Events stream to a managed log backend so your WP database stays lean.

== Description ==

Tracks user, content, plugin, theme, system, and integration activity on your
WordPress site and forwards every event to a managed log backend. Long-term
storage, search, retention, and cross-site aggregation happen off-server —
your WordPress database keeps only a small outgoing queue that drains
continuously.


= Why an external backend? =

Other activity log plugins write every event into a dedicated local table
(often using EAV-style metadata rows). On busy sites those tables grow into
millions of rows and start to dominate admin UI latency, backups, and
replication.

helloLOG moves storage off the WordPress database entirely. The backend is
built for write-heavy audit workloads with weekly chunking, compression,
and configurable retention — without touching your WP DB beyond a small
queue.

= Logged activity =

* Authentication: login/logout, failed logins, password reset, 2FA, app passwords
* Users: create / delete / role change / profile updates
* Content: posts, pages, CPTs, taxonomies, comments, menus, widgets, custom fields
* Plugins & themes: install / activate / deactivate / update / delete / file changes
* Settings & system: site URL, permalinks, discussion, WordPress core updates
* Files: wp-config.php, .htaccess, robots.txt, plugin/theme file modifications
* Multisite: sites, super admin grants, network settings
* Integrations: WooCommerce, Gravity Forms, WPForms, Yoast SEO, RankMath, ACF,
  bbPress, LearnDash, MemberPress, Paid Memberships Pro, TablePress,
  Redirection, MainWP, and more

Sensors lazy-load — an integration's hooks only register when the integration
is actually active on the site.

== Installation ==

1. Upload the plugin to `wp-content/plugins/hellolog/`.
2. Activate it through the **Plugins** menu in WordPress.
3. Go to **Tools → helloLOG → Settings**, paste the API key issued for your
   site, and click **Send test event**.
4. Optionally toggle sensors and IP anonymization in the **Filters** tab.

== Frequently Asked Questions ==

= Do my events stay on the same server as my WordPress site? =

No — by design. The plugin only buffers them locally for a few seconds before
pushing them to the configured backend. The backend is where retention,
search, and cross-site aggregation happen.

= What if the backend is unreachable? =

The local queue keeps events until delivery succeeds, with exponential
backoff. After repeated failures an entry moves to a dead-letter status,
visible in the **Diagnostics** tab.

= Where do I get an API key? =

Request one from your hellolog.io dashboard (https://app.hellolog.io) —
every site is issued its own key, bound to its domain. Paste it into
**Tools → helloLOG → Settings**.

== Changelog ==

= 0.4.3 =
* New: Built-in updates from GitHub releases — the Plugins screen now
  offers new helloLOG versions like any other plugin, no manual zip needed.
* New: The `hellolog_self_update` filter disables the built-in updater for
  managed setups, and the Diagnostics tab then links the latest zip for
  manual installs.

= 0.4.2 =
* New: Test connection now also refreshes your plan's API access
  immediately — a plan upgrade unlocks the wp-admin log view without
  waiting for the daily recheck.
* New: The Settings Connection tab shows a dashboard link with setup
  guidance when no API key is stored yet.
* Fix: CLI and Diagnostics copy said the queue drains every 30 seconds;
  it's 60.
* Fix: Token-format docblock matched to the actual validation.

= 0.4.1 =
* Change: WP-admin log view requires a paid plan — Free shows a dashboard
  link (logging itself is unaffected).

= 0.4.0 =
* New: Daily Action Scheduler recheck against the backend's `GET /verify` —
  keeps the stored key's validation state fresh between saves instead of
  only checking it once at Save time. A 200 response confirms it, 401/403
  clears it (sensors detach), network errors/5xx leave it unchanged so a
  backend blip can't silently stop logging.
* Change: Plugin now ships under the hellolog.io brand — Plugin URI, author,
  and support links point at hellolog.io / github.com/hellolog-io. See the
  "Where do I get an API key?" FAQ for the new dashboard link.
* Change: Default backend endpoint updated to `api.hellolog.io` (was
  `api.gobird.io`). This is the new helloLOG backend: after upgrading from
  0.3.x, create a site key at app.hellolog.io and reconnect in Settings.

= 0.3.2 =
* Fix: Stop the queue-flush Action Scheduler job from running away on an
  unconfigured or deconfigured install. The recurring action now exists only
  while the plugin is active and is torn down when the token is cleared; an
  orphaned action self-unschedules instead of ticking forever.
* Change: Drain cadence raised from 30s to 60s (filter `hellolog_flush_interval`,
  floored at 60s).
* Fix: Single-flight DB lock around the flush so concurrent async/cron runners
  no longer race the same batch (the source of the "action ignored" log flood).
* New: Daily pruner trims helloLOG's own finished actions/logs in the shared
  `actionscheduler_*` tables past a short retention (default 3 days, filter
  `hellolog_as_retention_days`) — without touching the site-global retention.

= 0.3.1 =
* New: `wp hellolog requeue-dead` — move every `dead` queue row back
  to `pending` so the flusher retries them. Use it after a backend
  outage / token rotation, once `wp hellolog test` confirms the
  backend is healthy again.

= 0.3.0 =
* New: License gate — the plugin only attaches sensors after a stored
  API key successfully delivers a test event to the backend. No more
  silent pile-up in the dead queue when the key is wrong or revoked.
* New: Save in **Tools → helloLOG → Settings** automatically fires a
  test event; the top-bar shows `Active`, `Awaiting validation`, or
  `Not active` accordingly.
* New: `wp hellolog clear-queue [--status=<status>]` to wipe the local
  outgoing queue in one shot (useful after a long stretch with a bad
  key).
* Change: `wp hellolog test` now stamps the license verified flag on
  HTTP 2xx so the next request actually attaches the sensors; failures
  reset the flag.
* Change: `wp hellolog status` reports the license state alongside the
  stored-key state, and no longer prints the backend URL.

= 0.2.0 =
* New: Failed login attempts split into their own `core-failed-login`
  sensor, off by default — noisy on internet-exposed sites, easy to flip
  on for incident investigation.
* New: Per-sensor descriptions on the Filters tab so you know what each
  sensor records before flipping its switch.
* New: API key disconnect button — wipe the stored key from the SPA
  without leaving WordPress.
* New: Backend domain pinning — each API key is bound to the site it
  was issued for; the plugin sends an `X-Site-Domain` header on every
  request, mismatches are rejected with 403.
* New: PHP-version guard — if the runtime is below 8.0 the plugin
  refuses to load and surfaces an admin notice instead of fataling.
* Change: Settings UI redesigned as a single Tools-level page with a
  sticky top bar, sidebar sub-tabs (Connection / Filters / Diagnostics),
  and shadcn-style components.
* Change: "Token" renamed to "API key" everywhere in the UI; the Save
  step now validates the format up-front instead of relying on the
  backend to reject malformed input.
* Change: README + Plugins-screen description trimmed so they no longer
  publish backend internals.

= 0.1.0 =
* New: Initial release — plugin scaffold, activation/deactivation lifecycle,
  outgoing queue table.
