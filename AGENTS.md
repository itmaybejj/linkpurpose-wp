# AGENTS.md

Guidance for AI coding agents (Claude Code reads this via the `CLAUDE.md` symlink) working in this repository.

## What this repo is

The WordPress port of the Drupal **Link Purpose Icons** module (`linkpurpose`). Both are thin wrappers around the same vanilla-JS library ([itmaybejj/linkpurpose](https://github.com/itmaybejj/linkpurpose)): they store a configuration object, hand it to the library on the front end, and the library marks links that open new windows, leave the site, download files, send email, make calls or launch apps.

The plugin lives at `wp-content/plugins/linkpurpose-wp/` inside a DDEV-managed WordPress **multisite** install (`editoria11y-wp-multisite`, project root two levels up) that it shares with the Editoria11y plugin. Everything outside this directory is stock WordPress or DDEV scaffolding — do not edit it. The sibling plugin `../editoria11y-wp-csa/` is the reference implementation for patterns ported here (network defaults, test harness, build pipeline); read it, don't modify it from this repo.

Upstream references on this machine:

- Drupal module: `~/sites/linkpurpose/web/modules/custom/linkpurpose`
- JS library (unminified): `~/sites/linkpurpose-library`
- Migration plan and session log: `temp-migration-plan.md` (gitignored scratch)

## Commands

Run from the plugin directory. Composer/npm/php/wp commands run inside the ddev container (`ddev exec -d /var/www/html/wp-content/plugins/linkpurpose-wp <cmd>`); `scripts/setup.sh` and `scripts/test.sh` dispatch themselves into the container when invoked from the host.

```bash
composer setup              # one-shot idempotent bootstrap (deps, test DB, npm, vscode seed)
composer test               # full gate: phpcbf, phpcs, phpmd, phpunit ×2, cspell, eslint, vitest, plugin-check
composer phpunit            # PHPUnit, single-site
composer phpunit-multisite  # PHPUnit with WP_MULTISITE=1 (network-override tests run only here)
composer phpcs / phpcbf     # WordPress coding standard (text domain + prefixes pinned in phpcs.xml.dist)
composer phpmd
composer test-js            # Vitest (jsdom) for assets/js/*.js
composer test-e2e           # Playwright against the DDEV site (not in the gate; `npm run test:e2e:install` once)
composer schema-fixture     # regenerate tests/js/fixtures/schema.json after ANY schema change (a PHPUnit test enforces it)
composer eslint
composer cspell
composer make-pot           # regenerate languages/linkpurpose.pot
composer dist               # build/linkpurpose-wp.zip via wp dist-archive + leak check
bash scripts/get.sh [ref]   # refresh assets/lib/ from the upstream library repo (never hand-edit assets/lib/)
```

Dev site: <https://editoria11y-wp-multisite.ddev.site> (admin/admin). Settings page: `wp-admin/options-general.php?page=linkpurpose`.

## Architecture

```
linkpurpose.php               entry: header, constants, PSR-4 autoloader (no Composer at runtime), boot
uninstall.php                 deletes options on every site
src/Plugin.php                hook orchestrator; Plugin::VERSION is the asset cache-buster
src/Installer.php             activate / uninstall; option-name constants
src/Settings/Schema.php       THE registry: every field (type, default, label, help, depends) + layout tree
src/Settings/Settings.php     read pipeline: network lock → stored value → schema default; filter linkpurpose_settings
src/Settings/FieldSanitizer.php  per-type/format sanitize rules (shared by the site and network validators)
src/Form/SettingsPage.php     Settings → Link Purpose; register_setting; page shell
src/Form/FormRenderer.php     Schema::layout() → fieldset/details/form-table HTML, context-aware
src/Form/SettingsContext.php  site|network render context: value, input name, disabled, lock note, mode dropdown
src/Form/SettingsValidator.php  register_setting sanitize callback; coerces network-locked keys
src/Form/ConditionalFields.php  data-linkpurpose-when attribute (Drupal #states stand-in) + script enqueue
src/Form/NetworkSettingsPage.php  Network Admin → Settings → Link Purpose; admin_post save handler, notices, heartbeat panel
src/Form/NetworkSettingsValidator.php  network_values[] + network_modes[] → {values, modes}; drops modes on empty text
src/Form/NetworkDefaultsWorker.php  wp_initialize_site seeder + WP-Cron backfill (cursor, coalesce, history, lock force-write)
src/Frontend/ConfigBuilder.php  effective settings → window.linkpurposeSettings payload; filter linkpurpose_js_settings
src/Frontend/Assets.php       wp_enqueue_scripts: library + CSS + inline payload (+ init shim unless themePreprocess)
assets/js/linkpurpose-wp.js   init shim (port of linkpurpose-drupal.js): validates selectors, builds options, new LinkPurpose()
assets/js/linkpurpose-admin.js  conditional-visibility toggles on the settings page
assets/js/linkpurpose-network.js  heartbeat client refreshing the backfill panel on the network page
assets/lib/                   vendored library (js/linkpurpose.js, .min.js, css/linkpurpose.css) — regenerate via scripts/get.sh
tests/                        PHPUnit (WP_UnitTestCase, real $wpdb) + tests/js/unit (Vitest)
```

### Conventions that matter

- **Storage keys are the Drupal module's camelCase names** (`purposeExternalIconType`, `noIconOnImages`, …). Keep them: the JS payload is a pass-through and a future Drupal→WP import needs no remapping. phpcs doesn't police array keys.
- **Add a field by adding one schema entry.** Form, sanitizer, read pipeline, JS payload and the schema integrity tests pick it up. Never hand-write a field renderer. Then make the init shim read it, run `composer schema-fixture`, and let `tests/js/unit/linkpurpose-wp-fields.test.js` prove the field changes the library options (that suite exists because fields have been dropped between form and library before).
- **Stored shape is all strings**: `'1'`/`''` for checkboxes, option key for selects. The validator writes every schema key on every save; the read pipeline uses `array_key_exists`, not `empty()`, so an unchecked checkbox (`''`) never falls back to a default of `'1'`.
- **Empty text = "use the library default".** PHP resolves only screen-reader messages (to translated defaults); the JS init resolves everything else, exactly like the Drupal initializer. Selector validity can only be tested in a browser, so it stays in JS.
- **Network defaults** use the Editoria11y model: `linkpurpose_network_settings` = `{values, modes}`, `modes[key] ∈ {new, all, lock}`. `lock` is overlaid at read time (`Settings`) and coerced on site saves (`SettingsValidator`). `new` seeds new sites on `wp_initialize_site`; `all` also backfills existing sites through `NetworkDefaultsWorker`, which overwrites a site value only when it is absent, equals any value the network ever published (`linkpurpose_network_history`) or equals the schema default; `lock` force-writes. The network page rejects "orphan" saves (value changed, mode none). Modes are kept on EVERY value, including a blank text value: blank means "use the plugin default", so `all` on a blank pushes sites back to the default and `lock` pins them there (this is a deliberate departure from Editoria11y, which drops such modes as inert). Worker writes bypass the Settings API sanitize filter on purpose (see `write_site_settings()`).
- **Text domain is `linkpurpose`** (pinned in phpcs.xml.dist and a test). It must equal the WordPress.org slug — confirm the slug before first submission.
- **Translations**: no `load_plugin_textdomain()`; WP.org just-in-time loading. Labels are built lazily in `Schema` so nothing translates before `init`.
- **No Composer at runtime.** `linkpurpose.php` registers its own PSR-4 autoloader; `vendor/` is dev-only and `.distignore`d.
- **Never edit `assets/lib/`.** Library changes go upstream, then `bash scripts/get.sh`.
- **Never commit on the user's behalf.** The user reviews every diff before committing.

### Testing

PHPUnit runs against a real database (`linkpurpose_test`, separate from Editoria11y's `wordpress_test`) via `wp-phpunit/wp-phpunit`; `tests/wp-tests-config.php` points ABSPATH at the container's `/var/www/html`. Run the suite twice — plain and `WP_MULTISITE=1` — because network tests `markTestSkipped()` on single-site. The network page tests drive `NetworkSettingsPage::save_from_post()` with the REAL rendered form serialized the way a browser posts it (see `serialize_form()` in class-test-network-settings-page.php); do the same for new save-path tests rather than hand-writing POST arrays — the orphan/default bugs only show up with what an untouched form actually posts. Vitest loads the classic scripts in `assets/js/` through dynamic `import()` with `vi.resetModules()` and stubs `globalThis.LinkPurpose` / `window.linkpurposeSettings`; the settings payload comes from `tests/js/fixtures/schema.json` (`payloadFromSchema()` in `tests/js/unit/helpers.js`), never a hand-written object. `linkpurpose-library.test.js` evaluates the real vendored library instead of the stub. Playwright specs in `tests/js/e2e/` drive the DDEV site through wp-cli helpers (`helpers/wp-state.js`); the library is a top-level `class` declaration, so in-page checks use the bare `LinkPurpose` identifier, not `window.LinkPurpose`.

When a test needs the settings form, render it with `FormRenderer::render()` (or `render_field_row()`) rather than hand-crafting markup; wrap in `SettingsContext::push('network')` / `pop()` for the network variant.

## Known upstream (Drupal) bugs fixed in the port — fix upstream too

- `js/linkpurpose-drupal.js`: fallback messages for `tel` and `newWindow` are swapped.
- `LinkpurposeSettings.php`: `purposeAppIconClasses` reads its default from `purposeDownloadIconClasses`; `purposeMailIconClasses` placeholder/description show the external-link classes; `purposeNewWindowIconClasses` description link text is wrong; `purposeTelIconPosition` label says "icon type".
