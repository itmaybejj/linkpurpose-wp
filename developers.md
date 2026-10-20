# Developer guide — Link Purpose Icons for WordPress

## Environment

| Tool | Why |
|------|-----|
| **DDEV** | Runs the WordPress multisite + MariaDB stack. Project root: `~/Sites/editoria11y-wp-multisite` (shared with the Editoria11y plugin). PHP 8.4, MariaDB 10.6, Node 24, WP-CLI, Composer 2 are all inside the web container. |
| **Git** | Cloning; `scripts/get.sh` pulls the upstream library over SSH. |
| **Bash** | The setup/test orchestrators. macOS `/bin/bash` 3.2 is fine. |

Site: <https://editoria11y-wp-multisite.ddev.site> — `admin` / `admin`. It is a subdirectory multisite (`/sandbox-N/` subsites exist), so network-admin screens are reachable.

Run commands inside the container:

```bash
ddev exec -d /var/www/html/wp-content/plugins/linkpurpose-wp composer test
ddev exec -d /var/www/html/wp-content/plugins/linkpurpose-wp composer phpunit
```

`scripts/setup.sh` and `scripts/test.sh` detect when they're on the host and re-exec themselves inside ddev, so `bash scripts/test.sh` works from anywhere.

## One-shot bootstrap

```bash
ddev start
bash scripts/setup.sh      # or: composer setup
```

Idempotent: `composer install`, `linkpurpose_test` database, `npm install`, `.vscode/settings.json` seed, library-bundle check.

## Day-to-day

```bash
bash scripts/test.sh        # everything (phpcbf, phpcs, phpmd, phpunit ×2, cspell, eslint, vitest, plugin check)
composer phpunit            # PHP tests, single-site
composer phpunit-multisite  # PHP tests with a network installed (WP_MULTISITE=1)
composer test-js            # Vitest
composer test-js-watch
composer phpcs / phpcbf / phpmd / eslint / cspell
composer make-pot           # regenerate languages/linkpurpose.pot (wp i18n)
composer dist               # build/linkpurpose-wp.zip + version-stamped copy, with leak check
bash scripts/get.sh [ref]   # refresh assets/lib/ from github.com/itmaybejj/linkpurpose
```

`wp plugin check` needs the Plugin Check plugin installed on the dev site (`ddev wp plugin install plugin-check --activate`); the test script skips the step with a note otherwise.

### VS Code

`.vscode/extensions.json` recommends phpsab (phpcs/phpcbf), ESLint, Code Spell Checker, EditorConfig. `composer setup` copies `.vscode/settings.example.json` to the gitignored `.vscode/settings.json`; the phpsab paths point at this plugin's `vendor/bin`.

## Layout

```
linkpurpose-wp/
├── linkpurpose.php      plugin entry (header, autoloader, boot)
├── uninstall.php
├── src/                 PSR-4, namespace LinkPurpose\  (see AGENTS.md for the class map)
├── assets/
│   ├── js/              hand-written: linkpurpose-wp.js (front-end init), linkpurpose-admin.js
│   ├── css/             linkpurpose-admin.css
│   └── lib/             VENDORED library — do not edit; scripts/get.sh regenerates
├── languages/           linkpurpose.pot (generated)
├── tests/               PHPUnit (+ tests/js/unit for Vitest)
├── scripts/             setup.sh, test.sh, install-wp-tests.sh, get.sh, build-dist.sh
├── build/               composer dist output (gitignored)
└── vendor/, node_modules/   dev-only (gitignored, .distignored)
```

## How configuration flows

1. **Schema** (`src/Settings/Schema.php`) declares every field once: type, default, label, help text, placeholder, and `depends` (conditional visibility).
2. **Settings page** renders the schema (`FormRenderer`) inside a standard `options.php` form. `register_setting()` routes the POST through `SettingsValidator::validate()`, which sanitizes per field type (`FieldSanitizer`), writes every key, and coerces network-locked keys.
3. **Read pipeline** (`Settings::all()`): network lock → stored value → schema default, then the `linkpurpose_settings` filter.
4. **Front end** (`Frontend\Assets`): enqueues `assets/lib/js/linkpurpose.min.js` + CSS, inlines `window.linkpurposeSettings = {…}` (built by `ConfigBuilder`, filterable via `linkpurpose_js_settings`), and enqueues `assets/js/linkpurpose-wp.js` unless the "Attach library then stop" option is on.
5. **Init shim** validates selectors in the browser, applies library-default fallbacks for empty values, calls `window.linkPurposeOptions(options)` if a theme defined it, then `new LinkPurpose(options)`.

Developer hooks:

| Hook | Purpose |
|------|---------|
| `linkpurpose_enabled` (filter, bool) | Return false to keep the plugin off a request. |
| `linkpurpose_settings` (filter, array) | Adjust effective settings after the read pipeline. |
| `linkpurpose_js_settings` (filter, array) | Adjust the payload; add `baseSelector`, `baseLinkClass`, `baseIconWrapperClass`, `noBreakClass`. |
| `linkpurpose_settings_validated` (action, array) | Fires on save with the sanitized settings; purge page caches here. |
| `window.linkPurposeOptions(options)` (JS) | Receive/replace the built options object before the library starts. |

## Caching

Static assets are versioned with `?ver=<Plugin::VERSION>`, so they're safe to cache far-future. The settings payload is inlined into each page: full-page caches (WP Super Cache, host-level caches, CDNs) hold the old payload until purged. Hook `linkpurpose_settings_validated` to purge, or purge manually after changing settings. There is no per-request option cost beyond two autoloaded option reads.

## Multisite

`linkpurpose_network_settings` (`wp_sitemeta`) holds `{values, modes}`; a key with mode `lock` overrides every site at read time and renders disabled on the site form. Modes `new` and `all` (seed new sites / backfill existing sites) are reserved for the network defaults page + cron worker to be ported from Editoria11y in the next session. Until then the option can only be written programmatically (`update_site_option`).

## Releasing

1. Bump `Version:` in `linkpurpose.php` and `Plugin::VERSION` in `src/Plugin.php` (a test and the dist build assert they match); update `Stable tag` + changelog in `readme.txt`.
2. Refresh the library if needed: `bash scripts/get.sh <tag>`.
3. `composer make-pot`.
4. `bash scripts/test.sh` — every step `✅`.
5. `composer dist` → `build/linkpurpose-wp-<version>.zip`.
