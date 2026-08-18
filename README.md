# helloLOG

WordPress activity log by **[hellolog.io](https://hellolog.io)**. Captures
every notable change on the site — logins, content edits, plugin/theme
operations, WooCommerce events, form submissions, … — and ships them to a
managed log backend so your WordPress database stays lean.


Out of the box: **43+ sensors**, WSAL-compatible code ranges, an
admin UI under **Tools → helloLOG**, WP-CLI subcommands (`wp hellolog
status|flush|test|sensors|…`), and a token-based privacy model
(per-site key, optional IP anonymisation).

## Install

### Composer (Bedrock / Roots / Composer-managed sites)

```sh
composer require hellolog-io/hellolog
```

The package is registered on [Packagist](https://packagist.org/packages/hellolog-io/hellolog).
The `wordpress-plugin` type plus `composer/installers` puts it under
`wp-content/plugins/hellolog/` automatically.

### Manual upload (classic sites)

1. Grab the latest `hellolog-<version>.zip` from
   [Releases](https://github.com/hellolog-io/hellolog-plugin/releases).
2. **WP Admin → Plugins → Add New → Upload Plugin** → upload, activate.

### Configure (both install paths)

Open **Tools → helloLOG → Settings**, paste the API key issued for
this site, and Save. The plugin fires a test event automatically; the
top bar should switch to `Active`. Until that test succeeds, no
sensors attach and no rows hit the local queue — see "License gate"
in `CHANGELOG.md` for the rationale.

## What it logs

| Range | Area |
|---|---|
| 1000–1099 | Auth (login / logout / failed / password reset) |
| 2000–2499 | Content, taxonomies, comments, menus, widgets |
| 4000–4599 | User profile / role, multisite, 2FA, app passwords |
| 5000–5599 | Plugins, themes, Redirection, TablePress, PMP, ACF |
| 5700–5899 | Form plugins (Gravity, WPForms, CF7, Fluent) |
| 6000–6499 | Settings / system / files / 404 / REST / XML-RPC |
| 7100–7799 | Database DDL + MainWP |
| 8000–8999 | bbPress, LearnDash, SEO (Yoast, RankMath), EDD, Termly |
| 9000–9499 | WooCommerce (products, orders, coupons, customers, settings) |

Each integration sensor is lazy-loaded — a sensor only attaches its
hooks when the underlying plugin is active, and you can disable any
sensor individually under **Settings → Filters**.

## Requirements

- WordPress **6.4+**
- PHP **8.0+**
- A site API key (request one through the hellolog.io dashboard,
  https://app.hellolog.io)

## Local development

```sh
composer install        # full dev dependency tree
composer phpcs          # WordPress Coding Standards
composer test           # PHPUnit
```

The Vue admin SPA source lives in the workspace repo, not here — this
repo ships only the compiled bundle under `assets/admin/`.

## Versioning

Updating the `Version:` line in `hellolog.php` and the `Stable tag:`
line in `readme.txt` (they must match) is enough — a push to `main`
triggers `.github/workflows/release.yml`, which builds the installable
zip and publishes a GitHub Release tagged `v<version>`.

## License

GPL-2.0-or-later.
