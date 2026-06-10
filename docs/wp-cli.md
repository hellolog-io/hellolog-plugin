# `wp hellolog` — WP-CLI reference

The plugin exposes a single `hellolog` command tree under WP-CLI.
Useful for headless setup (servers without admin UI access),
troubleshooting, and scripting bulk operations across a fleet.

The commands register at plugin boot, but only when `WP_CLI` is
defined — they don't cost anything on a regular web request.

A quick smoke test that the command tree is wired up:

```sh
wp hellolog status
```

If you get `Error: 'hellolog' is not a registered subcommand of 'wp'.`,
the plugin isn't loaded — the WordPress plugins screen will tell you
whether the activation succeeded.

---

## Commands

### `wp hellolog status`

Prints a one-screen snapshot of how the plugin is configured.

**Output fields**

| Field | What it means |
|---|---|
| `API key` | `set` / `missing` — whether the bearer is configured. |
| `Anonymize IP` | `on` / `off` — the privacy toggle from **Settings → Filters**. |
| `Queue table` | The fully-qualified queue table name (`{$wpdb->prefix}hellolog_queue`). |
| `pending / sending / dead` | Row counts per queue status. |
| `Disabled sensors` | Comma-separated sensor keys explicitly turned off. |

**Example**

```sh
$ wp hellolog status
API key:     set
Anonymize IP: off
Queue table: wp_hellolog_queue
  pending=0  sending=0  dead=0
Disabled sensors: core-request, core-failed-login
```

### `wp hellolog flush`

Triggers a manual queue drain instead of waiting for the next
30-second Action Scheduler tick. Useful right after a bulk import
when you want events delivered immediately.

```sh
$ wp hellolog flush
Success: Flush triggered.
```

Errors out with `No site token configured` if there's nothing to send.

### `wp hellolog test`

Sends a single synthetic event (`code 9999`, `severity info`,
`object: system`, `event_type: connection-test`) **bypassing** the
queue. The fastest end-to-end check that the API key works and the
backend accepts events from this site.

```sh
$ wp hellolog test
Success: HTTP 202 {"accepted":1,"rejected":null}
```

A failure prints the actual HTTP status and body:

```sh
$ wp hellolog test
Error: HTTP 403 {"error":"domain mismatch"}
```

### `wp hellolog set-token <token>`

Stores the bearer token in `wp_options.hellolog_token`. The single
positional argument is the **full** key including the prefix.

```sh
$ wp hellolog set-token <full-api-key>
Success: Token saved (last 4: 3ab9).
```

> **Caveat:** the CLI command does NOT enforce the format validation
> that the REST endpoint runs. If you paste garbage here the plugin
> will store it and the next request will fail with `401 malformed
> token`. Always copy the full key verbatim.

### `wp hellolog clear-token`

Wipes the stored token. The Logs page in **Tools → helloLOG** reverts
to its empty-state card; the outgoing queue dispatcher stops
attempting deliveries.

```sh
$ wp hellolog clear-token
Success: Token cleared.
```

### `wp hellolog sensors`

Lists every registered sensor with its current enabled state, in a
table — handy for piping into other tools or grepping for one
integration.

```sh
$ wp hellolog sensors
+---------------------+---------+-------------------+
| key                 | enabled | class             |
+---------------------+---------+-------------------+
| core-auth           | yes     | LoginLogoutSensor |
| core-failed-login   | NO      | FailedLoginSensor |
| core-user-profile   | yes     | UserProfileSensor |
| core-content        | yes     | ContentSensor     |
| …                                                  |
+---------------------+---------+-------------------+
```

Supports the standard WP-CLI `--format` switch:

```sh
wp hellolog sensors --format=json
wp hellolog sensors --format=csv
wp hellolog sensors --format=yaml
```

### `wp hellolog requeue-dead`

Move every `dead` row in the local outgoing queue back to `pending`
so the flusher retries them on the next tick. Use this after a
backend outage or token rotation has filled the dead pile and the
backend is healthy again.

```sh
$ wp hellolog status              # 12 dead, 0 pending
$ wp hellolog test                # confirm backend accepts a ping
$ wp hellolog requeue-dead
Success: Requeued 12 row(s) from dead to pending. Run `wp hellolog flush` to send them now.
$ wp hellolog flush
Success: Flush triggered.
$ wp hellolog status              # 0 dead, 0 pending (or whatever's left after errors)
```

Successful deliveries delete the rows. Failures re-enter the normal
back-off ladder (`attempts=0` is reset on requeue, so they get the
full retry budget again).

### `wp hellolog enable-sensor <key>`

Removes the `disabled` flag from the sensor identified by `<key>`.
Effective on the next request.

```sh
$ wp hellolog enable-sensor core-failed-login
Success: Sensor core-failed-login enabled.
```

### `wp hellolog disable-sensor <key>`

Marks the sensor as disabled. The plugin stops attaching its hooks on
the next request, so even high-volume hooks (e.g. `core-request`)
become genuinely zero-cost.

```sh
$ wp hellolog disable-sensor core-request
Success: Sensor core-request disabled.
```

---

## Recipes

### First-time setup on a new site

```sh
wp hellolog set-token <full-api-key>
wp hellolog test
wp hellolog status
```

### Disable noisy sensors on a public, internet-exposed site

```sh
# These two ship off by default for fresh installs, but if you
# upgraded from < 0.2.0 they may still be active.
wp hellolog disable-sensor core-failed-login
wp hellolog disable-sensor core-request
wp hellolog status
```

### Troubleshooting: events aren't reaching the backend

```sh
wp hellolog status           # token set? queue counts?
wp hellolog test             # round-trip a single event — see exact HTTP body
wp hellolog flush            # drain everything pending right now
wp hellolog status           # did pending drop to 0?
```

If `wp hellolog test` returns `403 domain mismatch`, the API key was
issued for a different host. Request a fresh key for this domain.

### Rotate the API key across many sites

```sh
# Example: pipe a domain → key map into per-site set-token calls.
jq -r 'to_entries[] | "\(.key)\t\(.value)"' hellolog_keys.json \
  | while IFS=$'\t' read -r domain token; do
      ssh "$domain" "wp hellolog set-token $token --path=/var/www/$domain"
    done
```

The plugin treats `set-token` as a full replacement, not an append.

---

## How it plugs in

```
HelloLog\Cli\Registrar::register()      ← called from Plugin::boot()
  └─ guards on `WP_CLI && defined('WP_CLI')`
  └─ WP_CLI::add_command('hellolog', \HelloLog\Cli\Command::class)

HelloLog\Cli\Command                    ← public methods become subcommands
  ├─ status()
  ├─ flush()
  ├─ test()
  ├─ set_token()           → `set-token`
  ├─ clear_token()         → `clear-token`
  ├─ clear_queue()         → `clear-queue`
  ├─ requeue_dead()        → `requeue-dead`
  ├─ sensors()
  ├─ enable_sensor()       → `enable-sensor`
  └─ disable_sensor()      → `disable-sensor`
```

The PHP method names use `_`, but the public subcommands are
`-`-separated (declared via `@subcommand` annotations on each method),
matching the rest of the WP-CLI ecosystem.
