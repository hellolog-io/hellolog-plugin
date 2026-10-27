# Changelog

## [0.6.0] - 2026-10-27

### Added
- Post, page and custom post type events also compare the status, the
  categories, tags and other terms, the sticky flag, the featured image and
  the page template, as the post ends up after the save, so a block editor
  save includes what the editor writes after the post itself. Terms and the
  sticky flag changed outside a save (by a plugin, for instance) are logged
  as an update of the post. A change to the content links to the revision
  WordPress saved for it.
- New post events: scheduled (with the date it goes live), submitted for
  review, and made private.
- Templates, template parts and global styles from the site editor are
  logged. Their events, and those of navigation menus and patterns, name
  them the way the site editor does ("Template", "Pattern").
- Files of the media library: an upload, an edit of the title, caption,
  description or alternative text, and a permanent deletion are logged, with
  the file as the target. The description is reported as changed, without
  its text. An upload that sets the alternative text is one event. The zip
  the plugin and theme installer keeps while it works is not logged.
- Profile edits that change the first or last name, the nickname or the
  biographical info are now logged as profile updates (the biographical info
  as changed, without its text).
- A completed password reset (a new password set from the reset link) is
  logged, without the new password.
- A role given to or taken from a user on its own (as plugins that manage
  several roles per user do) is logged as a role change listing every role
  the user had before and after. A registration records every role the new
  user got, and deleting a user records who their content was given to.
- Plugin and theme events name the plugin or theme as their target, so its
  history can be followed; a Customizer save names the active theme.
  Updates list the version change (old to new), and a WordPress core update
  lists the version it came from.
- Turning automatic updates on or off for a plugin or theme is logged.
- More core settings are watched: the discussion settings (default comment
  and ping status, closing comments on old posts and after how many days,
  previously approved comment authors, links before moderation, threaded
  comments, the comment moderation and disallowed word lists), the UTC
  offset, the site icon, the privacy policy page, the default category and
  organizing uploads into month- and year-based folders. The word lists are
  reported as changed, without their words. A watched setting saved for the
  first time is logged too.
- Role capability edits are a new critical event: which roles were added or
  removed and which capabilities each role gained or lost, one event per
  site and request. The capabilities that run the site (settings, users,
  plugins, themes, code, the network) are listed first. The roles WordPress
  sets up for a new site are not logged.
- Term edits (categories, tags, custom taxonomies) list what changed: the
  name, the slug, the parent and the description (as changed, without its
  text). A menu save reports a rename. Term and menu events name the term or
  menu as their target.
- Changes to helloLOG's own settings are a new critical event: the API key
  (only that it was set, replaced or removed, never its value), IP
  anonymization, and each sensor turned on or off. This logging can not be
  turned off and has no switch in the settings. Like all logging it needs a
  verified API key; clearing the key is still logged.
- A replaced or cleared API key, and a key that is no longer verified (a new
  key, a cleared one, a failed connection test), are reported and sent right
  away, under the key the site was verified with, so the record reaches the
  account the site belonged to.
- Deactivating helloLOG is logged and sent right away, before the plugin
  stops, together with what is still in the queue (for up to two seconds);
  events queued later in the same request are sent at its end. Deactivated
  for a whole network, every other active site that has a key sends its own
  deactivation as well, as many sites as five seconds allow.

### Changed
- A status event (published, scheduled, made private and the others) lists
  the other changes made in the same save, including a sticky flag that the
  classic editor, quick edit or bulk edit sets right after the status
  change.
- A status change that has no event of its own (a post sent back to draft
  from pending review, scheduled or private) is logged as an update listing
  the status change; it was not logged before.
- A new post saved straight as pending, scheduled or private is logged as
  submitted for review, scheduled or made private instead of "created".
- A changed e-mail address and a changed password are high-severity events
  of their own ("E-mail address changed", with the old and the new address,
  and "Password changed") instead of part of the profile update. The
  password is never recorded, only that it changed.
- A term save that changed nothing is no longer logged, and several edits of
  one term in a request are one event.
- The settings screen, `wp hellolog enable-sensor` and
  `wp hellolog disable-sensor` accept only the plugin's own sensors.
  `wp hellolog sensors` shows whether each sensor runs, including the ones
  that are off by default.

### Fixed
- Installing a plugin or theme is logged; it never was. Uploading a zip over
  an installed plugin or theme is logged as the update it is, with the
  version change.
- A deleted plugin or theme is named by its real name instead of its file or
  folder name.
- Menu events name the menu also when only its items were saved or when it
  was deleted; the name used to be empty.
- With the media trash turned on, moving a file to the trash is logged; it
  never was. A file's trash and restore read "Media", like its other events.
- bbPress forums, topics and replies, LearnDash courses, lessons, topics and
  quizzes, Easy Digital Downloads downloads and ACF field groups are no
  longer logged twice: the events their own sensors report are left to
  them, and everything else about them (a download's deletion, a lesson's
  edit) is still logged.
- Creating or deleting a site of a network no longer logs a database event
  for each of that site's tables; the site's creation and deletion are
  logged as one event each. Dropping helloLOG's own queue table (on
  uninstall, or with a deleted site) is no longer logged either, like
  creating it.
- Saving the API key that is already stored (in the settings or with
  `wp hellolog set-token`) no longer switches logging off until the key is
  verified again, and `wp hellolog set-token` then says whether that key is
  verified.

### Security
- A CREATE TABLE or DROP TABLE statement can no longer slip past the
  database sensor: comments, unusual whitespace and the plural DROP TABLES
  no longer hide it, every table a DROP TABLE names is logged (not only the
  first), and a statement the sensor can not read precisely is logged as it
  is, marked as unparsed.

## [0.5.0] - 2026-10-26

**Upgrade note:** This version requires the HelloLog service release from
October 2026 or later.

### Added
- Events now separate who did it (the actor) from what it was done to (the
  target). Targets are recorded for users, posts, pages and custom post
  types, comments, WooCommerce orders and settings; links back to the edit
  screen are recorded for posts, comments and orders. Background runs (cron,
  Action Scheduler) have no actor. Changing another user's profile or role
  records you as the actor and them as the target.
- Events record where they came from: `admin`, `frontend`, `rest`, `ajax`,
  `cron`, `action-scheduler`, `cli` or `xmlrpc`.
- Events list what changed as old and new values per field. Permanently
  deleted items have nothing to open, so they carry no edit link.
- Events carry template variables, and the message is rendered from them.
- WooCommerce order notes are logged with their text and type (customer,
  private, system or status). Orders get a target and a link to the order
  edit screen.
- Posts reverted to auto-draft are now logged, from any status (a published
  or trashed post included), for WooCommerce products and coupons too.
- A post update also records changes to the publish date, the menu order and
  password protection (whether a password is set, never the password itself).
- New `hellolog_suppression_rules` filter to add suppression, merge or
  drop-empty rules for the per-request de-duplication.

### Changed
- The events of one request are buffered and de-duplicated before they are
  queued: when several events describe the same change, the most specific one
  wins. An order note is no longer logged twice, a plain status-transition
  note folds into the order status change, and the several "post updated"
  events of one save become one.
- Publishing a new post logs one "published" event instead of "created"
  plus "published".
- Plugin settings changes (WooCommerce, Rank Math, Yoast SEO, Termly and the
  LW plugins) record the HTTP method of the request that made them; changes
  made with WP-CLI have none.
- Comment events cover comments, pingbacks, trackbacks, reviews and notes
  left on a post in the block editor.
- Profile edits that only touch the first or last name, the nickname or the
  biographical info are not logged yet; they will be in a later release.
- Event times are recorded to the millisecond.
- Long values in an event's details are cut to 1,024 characters.
- A sign-in with an application password is logged at most once an hour per
  user, application password and IP address, so an integration that polls
  the REST API no longer floods the log.

### Fixed
- Requests authenticated with an application password (REST API or XML-RPC
  clients, mobile apps, integrations) no longer run out of memory.
- A new registration no longer logs a false role change.
- "Post updated" events that changed nothing are dropped.
- Writes that plugins make to their own bookkeeping options (queues,
  notices, caches, version stamps) are no longer logged as settings changes
  in the WooCommerce, Rank Math, Yoast SEO, Termly and LW plugin sensors, and
  neither are option changes made by background tasks (cron, Action Scheduler)
  or by visitors.
- Deleting auto-drafts during WordPress' own cleanup is no longer logged.
- Menu changes are no longer logged twice.
- WooCommerce products, orders and coupons are no longer also logged as
  generic posts. Moving products and coupons to the trash and restoring them
  is still logged.
- Changes to core settings (site title, tagline, site address, admin e-mail
  and the other watched settings) record the setting as their target and
  what changed, and their values are masked like those of plugin settings: a
  password in a URL no longer reaches the log.
- Restoring a post, page, product or coupon from the trash in the block
  editor or through the REST API is now logged.
- Approving a comment through the REST API (the block editor, apps) no longer
  also logs "comment edited". An edit is logged only when the text or the
  author's name, e-mail or URL changed, and lists what changed.
- On multisite, network activation sets up every site (within a time limit,
  and not on large networks), sites added later are set up when they are
  created, and any site still without its queue table is set up on its
  first event, so their events are no longer lost. Events written while a
  site is being deleted are kept in the main site's queue. Uninstalling
  removes the queue table and helloLOG's settings (the API key included) on
  every site, and deleting a site removes its queue table.
- Titles that WordPress stores with HTML entities (such as order titles)
  read as plain text in event targets and messages.
- Creating helloLOG's own queue table is no longer logged as a database
  change.
- On multisite, every event is queued on the site it happened on, also when
  a request switches between sites.
- A privileged role assigned at registration is always logged.
- Logging in with two-factor authentication is no longer logged as "2FA
  enabled".

### Security
- Credential-like values are masked in option values and in recorded changes,
  including nested keys in arrays and JSON strings, passwords in URLs,
  credential-like URL query parameters, webhook URLs and bank details.

## [0.4.4] - 2026-10-26

### Added
- The local outgoing queue is bounded: after every drain tick, `dead` rows
  older than 7 days are removed and the queue is trimmed to 50,000 rows,
  oldest first (`Queue\QueueRetention`). Both limits are filterable via
  `hellolog_queue_dead_retention_days` and `hellolog_queue_max_rows`.

### Changed
- `session_id` in event payloads is now the SHA-256 of the login session
  token (the same key core uses for `session_tokens`), never the token
  itself (`RequestContext::session_fingerprint()`).
- WooCommerce settings and LW plugin settings events mask the value of
  options whose names look like credentials (`Sensors\SensitiveOption`).
- The lw-firewall blocked-request event applies the IP anonymisation
  setting to the blocked client's IP.

## [0.4.3] - 2026-08-19

### Added
- Built-in updates from GitHub releases: the Plugins screen now offers new
  helloLOG versions like any other plugin, no manual zip needed
  (`Updater\GitHubUpdater`, `Updater\ReleaseInfo`). Only the exact
  `hellolog-<version>.zip` release asset is ever offered as a package —
  never GitHub's auto-generated zipball/tarball, whose top-level folder
  name would break the install — and only a `browser_download_url` under
  `github.com/hellolog-io/hellolog-plugin/releases/download/` is accepted.
  The GitHub API response is cached in a transient for 12 hours (1 hour on
  failure) so an outage or rate limit isn't hammered on every admin load.
- The `hellolog_self_update` filter disables the built-in updater for
  managed setups (`add_filter( 'hellolog_self_update', '__return_false' )`),
  and the Diagnostics tab then links the latest zip for manual installs.

## [0.4.2] - 2026-08-19

### Added
- Test connection now also refreshes your plan's `api_access` immediately:
  a successful "Send test event" in Settings → Connection triggers the same
  `GET /verify` call the daily recheck makes, so a plan upgrade unlocks the
  wp-admin log view without waiting for the next tick
  (`TestConnectionHandler::refresh_api_access()`, reuses `TokenVerifier` and
  `VerifySchedule::api_access_from_body()`). Best-effort: a transport error
  or non-200 leaves `api_access` untouched and never changes the test
  result reported to the operator.
- The Settings Connection tab shows a dashboard link with setup guidance
  when no API key is stored yet.

### Fixed
- CLI (`wp hellolog requeue-dead`) and the Diagnostics tab said the queue
  drains every 30 seconds; it's 60 (`FlushSchedule::MIN_INTERVAL`).
- `Options::is_valid_token()`'s docblock now matches the actual regex
  (full-string match, 8/40-char lowercase-alphanumeric prefix/secret).

## [0.4.1] - 2026-08-18

### Changed
- WP-admin log view requires a paid plan — Free shows a dashboard link
  (logging itself is unaffected). The daily token recheck now also parses
  `GET /verify`'s `api_access` field (200 body `{status, site_id, domain,
  plan, api_access}`) into a new `hellolog_api_access` option; a legacy
  backend body that omits the field, or an empty/invalid body, leaves it
  untouched, which defaults to `true` so an old backend never shows the
  upsell (`Options::api_access()`, `VerifySchedule::api_access_from_body()`,
  `VerifyScheduleBridge::run()`). The Vue admin SPA renders a
  `PlanUpsellCard` in place of the Activity Log table/filters when the
  bootstrap payload's `api_access` is `false`.

## [0.4.0] - 2026-08-18

### Added
- Daily token recheck: a new `hellolog_verify_token` Action Scheduler job
  calls the backend's `GET /verify` once a day for as long as a token is
  configured, and applies the result to the same "verified" flag `wp hellolog
  test` / Save Settings already set — `200` confirms it, `401`/`403` clear it
  (catching a suspended or revoked token between manual checks), any other
  response (network error, `5xx`, `429`) leaves the flag untouched rather than
  flipping sensors off on a transient blip. Mirrors the flush job's lifecycle
  rules (`includes/Scheduler/VerifySchedule.php`,
  `includes/Scheduler/VerifyScheduleBridge.php`,
  `includes/Transport/TokenVerifier.php`).

### Changed
- Brand cutover to hellolog.io: plugin header (Plugin URI, Author, Author
  URI), `composer.json` (package name, homepage, support links), and
  `readme.txt` now point at hellolog.io / github.com/hellolog-io instead of
  hellowp.io / gobird.io.
- Default backend endpoint (`Options::ENDPOINT_URL`) updated to
  `api.hellolog.io` (was `api.gobird.io`). This points at the new helloLOG
  backend: sites upgrading from 0.3.x must create a site key in the
  dashboard at app.hellolog.io and reconnect (Settings → paste key → Test
  connection) — keys issued for the old backend do not carry over.
- The Vue admin SPA's bootstrap data (`window.hellologAdmin`) now carries a
  `dashboard_url` field (`https://app.hellolog.io`) for onboarding /
  "get a key" links.

## [0.3.2] - 2026-06-19

### Fixed
- Queue-flush Action Scheduler job no longer runs away on an unconfigured or
  deconfigured install. The `hellolog_flush_queue` recurring action is created
  only while the plugin is active (`Options::is_active()`) and is unscheduled
  when the token is cleared; the hook callback is bound unconditionally so an
  orphaned action left by an older build self-unschedules instead of ticking
  forever with no handler. Root cause of a production outage on 2026-06-19.
- Single-flight MySQL advisory lock (`GET_LOCK`) around the flush so concurrent
  Action Scheduler async (loopback) and WP-Cron runners can't race the same
  batch — this was the source of the "action ignored" / "unable to mark this
  action" log flood (17.9M rows / 3 GB on the affected site).

### Changed
- Drain cadence raised from 30s to 60s, floored and filterable via
  `hellolog_flush_interval`. Existing installs migrate automatically (a stored
  schedule version drops the stale 30s action so it reschedules at 60s).

### Added
- Daily group-scoped pruner (`hellolog_prune_as`) deletes helloLOG's own
  finished actions/logs from the shared `actionscheduler_*` tables past a short
  retention (default 3 days, filter `hellolog_as_retention_days`) via the public
  Action Scheduler API. The site-global retention is left untouched.

## [0.3.1] - 2026-06-10

### Added
- `wp hellolog requeue-dead` — move every `dead` row in the local
  queue back to `pending` so the flusher retries them. Use after a
  backend outage or token rotation, once `wp hellolog test` confirms
  the backend is healthy again. The follow-up `wp hellolog flush`
  drains the recovered rows; successful deliveries delete them,
  failures re-enter the normal back-off ladder.

## [0.3.0] - 2026-05-14

### Added
- **License gate.** The plugin now refuses to attach sensors until the
  stored API key has successfully delivered a test event to the
  backend. Without this gate a wrong/revoked key would silently bury
  the local queue in `dead` rows (one site reported 25k of them).
  Sensors stay detached until `Options::mark_active(true)` flips —
  driven by `TestConnectionHandler`, `wp hellolog test`, or the new
  Save-then-test flow in the SPA.
- Top-bar status now distinguishes three states: `Active` (green),
  `Awaiting validation` (amber — key stored but not yet verified), and
  `Not active` (red — no key).
- Logs tab empty-state is split: missing-key versus
  pending-validation get separate copy.
- `wp hellolog clear-queue [--status=<status>]` to wipe the local
  outgoing queue. Useful after a long stretch with a bad key — the
  dead pile-up can be deleted in one shot.

### Changed
- Saving an API key in the SPA fires a test event automatically; a
  successful round-trip activates the license, a failure leaves the
  key stored but inactive with an explanatory toast.
- `wp hellolog test` flips the verified flag on a 2xx response,
  resets it on anything else.
- `wp hellolog set-token` / `clear-token` reset the verified flag, so
  the next request needs an explicit `wp hellolog test` to bring the
  license back online.
- `wp hellolog status` reports the license state alongside the stored
  key, and no longer prints the backend URL (in keeping with the
  earlier doc sanitisation).

## [0.2.0] - 2026-05-13

### Added
- Dedicated `core-failed-login` sensor, off by default. Failed
  WordPress login attempts no longer travel with the everyday auth
  events — operators flip it on from **Settings → Filters** when
  they're investigating an incident.
- Per-sensor descriptions on the Filters tab so the on/off decision
  doesn't depend on guessing what the slug means.
- "Remove API key" disconnect button on the Connection tab, and a
  client-side format check before saving the key.
- Backend domain pinning: every request carries an `X-Site-Domain`
  header; tokens issued for one site won't authenticate from another.
- PHP-version guard in the plugin bootstrap. On PHP < 8.0 the plugin
  stops loading at the first `require` and shows a single admin
  notice instead of fataling the whole site.

### Changed
- Admin UI consolidated into a single Tools-level page (`Tools →
  helloLOG`) with a sticky top bar, sidebar sub-tabs and shadcn-vue
  components. The old Settings-menu entry is retired.
- "Token" relabelled to "API key" everywhere user-facing.
- README, plugin-header description and composer description no longer
  mention the backend stack — those internals stay in the private
  workspace docs.

## [0.1.0] - 2026-05-11

### Added
- Plugin scaffold: header, Composer + PSR-4 autoloading under
  `HelloLog\`, activation / deactivation lifecycle, queue
  table (`{$wpdb->prefix}hellolog_queue`), clean uninstall.
- Event catalog covering 100+ codes across WP core (auth, users, content,
  comments, taxonomies, plugins, themes, settings, system, files,
  database, menus, widgets, multisite, request, 2FA, app passwords) and
  integrations (WooCommerce, Gravity Forms, WPForms, CF7, Fluent Forms,
  Yoast SEO, RankMath, ACF, bbPress, LearnDash, MemberPress, PMP, EDD,
  TablePress, Redirection, MainWP, Termly, plus the LW family).
- Sensors register lazily — integration sensors only attach their hooks
  when the target plugin is active.
- Outgoing queue (`QueueRepository` + `QueueEventDispatcher`).
- Transport (`ApiClient` with gzip + Bearer + retry policy,
  `PayloadBuilder`, `QueueFlusher`) and Action Scheduler bridge that
  flushes every 30 s.
- Tabbed Settings page: **Connection** (endpoint + token + Test
  Connection), **Filters** (sensor on/off + IP anonymization),
  **Diagnostics** (queue counts).
- **Tools → Activity Log** admin page with filter bar, AJAX paging, and
  read API call to the backend.
- PHPUnit, PHPCS (WordPress + PHPCompatibility), and GitHub Actions
  workflows for CI + release.
