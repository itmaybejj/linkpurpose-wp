---
name: setup
description: Bootstrap or repair the linkpurpose-wp local environment. Use when asked to "set up", "get this running", "make tests work", or when a test run fails with missing vendor/, node_modules/, or database errors.
---

# Set up the local environment

Everything is scripted; don't reimplement the steps.

1. `ddev start` from `~/Sites/editoria11y-wp-multisite` (the WP root, two levels up).
2. `bash scripts/setup.sh` from the plugin directory (host or container) — idempotent, reports per-step ✅/⏭️/❌.
3. `bash scripts/test.sh` — the full gate. Green means the environment works.

Multisite: the dev site is already a subdirectory multisite; PHPUnit installs its own network when run with `WP_MULTISITE=1` (`composer phpunit-multisite`). The test database is `linkpurpose_test` (not Editoria11y's `wordpress_test`).

Library bundle missing (`assets/lib/` empty): `bash scripts/get.sh` on the host (clones over SSH).

Plugin Check step skipped: `ddev wp plugin install plugin-check --activate`.
