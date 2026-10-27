=== helloLOG ===
Contributors:      hellologio
Tags:              activity log, audit log, security, monitoring, woocommerce
Requires at least: 6.4
Tested up to:      6.7
Requires PHP:      8.0
Stable tag:        0.6.0
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

== Upgrade Notice ==

= 0.5.0 =
This version requires the HelloLog service release from October 2026 or later.

== Changelog ==

= 0.6.0 =
* New: Post events also compare the status, the terms, the sticky flag, the
  featured image and the page template, as the post ends up after the save
  (block editor saves included). Terms and the sticky flag changed outside a
  save are logged as an update. A content change links to its revision.
* New: Post events for scheduled (with the date), submitted for review and
  made private.
* New: Site editor templates, template parts and global styles are logged,
  named the way the site editor names them.
* New: Media uploads, edits of a file's title, caption, description or
  alternative text, and permanent deletions are logged.
* New: Edits of the first or last name, the nickname or the biographical
  info are now logged as profile updates.
* New: A completed password reset is logged.
* New: A role given to or taken from a user on its own is logged as a role
  change. Registrations list every role; deleting a user records who got
  their content.
* New: Plugin and theme events name the plugin or theme as their target.
  Updates list the old and the new version; a core update lists the version
  it came from.
* New: Turning automatic updates on or off for a plugin or theme is logged.
* New: More core settings are watched: the discussion settings, the UTC
  offset, the site icon, the privacy policy page, the default category and
  the upload folders. Comment word lists are reported as changed, without
  their words.
* New: Role capability edits are logged as a critical event: roles added or
  removed, capabilities gained or lost.
* New: Term edits list what changed (name, slug, parent, description); a
  menu save reports a rename.
* New: Changes to helloLOG's own settings (the API key, never its value; IP
  anonymization; sensors turned on or off) are logged as a critical event
  that can not be turned off. Key changes are sent at once, with the key in
  use before the change.
* Change: Deactivating helloLOG is now sent right away, before the plugin
  stops, instead of waiting in the queue until the plugin is turned on
  again; it is logged even when the plugin sensor is off. Deactivated
  network-wide, it is also sent from the other sites with a key, within a
  time limit.
* Change: A status event lists the other changes of the same save; a post
  sent back to draft is logged as an update.
* Change: A new post saved straight as pending, scheduled or private is
  logged as such instead of "created".
* Change: E-mail address and password changes are high-severity events of
  their own; the password is never recorded.
* Change: A term save that changed nothing is no longer logged.
* Change: The settings screen and WP-CLI accept only the plugin's own
  sensors; `wp hellolog sensors` shows which sensors run, defaults included.
* Fix: Plugin and theme installs are logged; uploading over an installed one
  is logged as an update.
* Fix: Deleted plugins and themes are named by their real name.
* Fix: Menu events name the menu also on item-only saves and deletions.
* Fix: With the media trash turned on, moving a file to the trash is logged.
* Fix: bbPress, LearnDash, Easy Digital Downloads and ACF content is no
  longer logged twice.
* Fix: Creating or deleting a network site no longer logs a database event
  per table; dropping helloLOG's own queue table is not logged.
* Fix: Saving the API key that is already stored no longer switches logging
  off.
* Security: Comments (version comments included), unusual whitespace (a
  no-break space included) and the plural DROP TABLES no longer hide a
  CREATE TABLE or DROP TABLE statement from the database sensor; each table
  a DROP TABLE names is logged, and a statement the sensor can not read
  precisely is logged as it is, marked as unparsed.

= 0.5.0 =
* Note: This version requires the HelloLog service release from October
  2026 or later.
* New: Events separate who did it (the actor) from what it was done to (the
  target). Targets are recorded for users, posts, pages and custom post
  types, comments, WooCommerce orders and settings; links back to the edit
  screen are recorded for posts, comments and orders. Background runs (cron,
  Action Scheduler) have no actor.
* New: Events record where they came from: admin, frontend, REST API, AJAX,
  cron, Action Scheduler, WP-CLI or XML-RPC.
* New: Events list what changed (old and new values). Messages are rendered
  from template variables.
* New: WooCommerce order notes are logged with their text and type
  (customer, private, system or status).
* New: Posts reverted to auto-draft are now logged, from any status, also
  for WooCommerce products and coupons.
* New: A post update also records changes to the publish date, the menu
  order and password protection (whether a password is set, never the
  password itself).
* New: The `hellolog_suppression_rules` filter adjusts the per-request
  de-duplication rules.
* Change: The events of one request are de-duplicated and the most specific
  event wins — an order note is no longer logged twice, plain status notes
  fold into the order status change, and publishing a new post logs
  "published".
* Change: Plugin settings changes (WooCommerce, Rank Math, Yoast SEO, Termly,
  LW plugins) record the HTTP method.
* Change: Comment events cover comments, pingbacks, trackbacks, reviews and
  notes left on a post in the block editor.
* Change: Profile edits that only touch the first or last name, the nickname
  or the biographical info are not logged yet; they will be in a later
  release.
* Change: Event times are recorded to the millisecond.
* Change: Long values in an event's details are cut to 1,024 characters.
* Change: A sign-in with an application password is logged at most once an
  hour per user, application password and IP address.
* Fix: Requests authenticated with an application password no longer run
  out of memory.
* Fix: A new registration no longer logs a false role change.
* Fix: Empty "post updated" events are dropped.
* Fix: Plugins' own bookkeeping writes and background option changes are no
  longer logged as settings changes.
* Fix: WordPress' own auto-draft cleanup is no longer logged.
* Fix: A two-factor login is no longer logged as "2FA enabled".
* Fix: Menu changes are no longer logged twice.
* Fix: WooCommerce products, orders and coupons are no longer also logged as
  generic posts. Moving products and coupons to the trash and restoring them
  is still logged.
* Fix: Core settings changes record the setting and what changed, with
  masked values: a password in a URL no longer reaches the log.
* Fix: Restoring from the trash in the block editor or through the REST API
  is logged.
* Fix: Approving a comment no longer also logs an edit; an edit lists what
  changed.
* Fix: On multisite, every site gets its queue table (at network
  activation, when it is created, or on its first event), so its events are
  no longer lost. Events written while a site is being deleted are kept in
  the main site's queue. Uninstalling removes the queue tables and helloLOG's
  settings on every site; deleting a site removes its queue table.
* Fix: Titles stored with HTML entities read as plain text in event targets
  and messages.
* Fix: Creating helloLOG's own queue table is no longer logged as a database
  change.
* Fix: On multisite, every event is queued on the site it happened on, also
  when a request switches between sites.
* Fix: A privileged role assigned at registration is always logged.
* Security: Credential-like values are masked in option values and in
  recorded changes, including nested keys in arrays and JSON strings,
  passwords in URLs, credential-like URL query parameters, webhook URLs and
  bank details.

= 0.4.4 =
* Change: Events carry a hash of the login session instead of the session
  token itself.
* Change: Option values whose names look like credentials (keys, secrets,
  tokens, passwords) are masked in WooCommerce and LW plugin settings events.
* Change: The lw-firewall blocked-request event respects the IP
  anonymisation setting.
* New: The local outgoing queue is bounded — undeliverable rows are removed
  after 7 days and the queue is capped at 50,000 rows
  (`hellolog_queue_dead_retention_days`, `hellolog_queue_max_rows` filters).

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
