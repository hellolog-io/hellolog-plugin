# Changelog

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
  hellowp.io / gobird.io. `readme.txt` keeps a heritage credit line for the
  original hellowp.io and gobird.io teams.
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
