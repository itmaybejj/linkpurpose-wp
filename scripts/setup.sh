#!/usr/bin/env bash
#
# One-shot, idempotent bootstrap for a fresh checkout. Every step checks
# "already done" before acting, so re-running is the safe move.
#
#   1. composer install           PHP dev deps (WPCS auto-registers for phpcs)
#   2. composer install-test-suite
#                                 provisions the linkpurpose_test database
#   3. npm install                JS dev deps (ESLint, Vitest)
#   4. seed .vscode/settings.json from the committed example (never overwrites)
#   5. check assets/lib/ is populated (tells you to run scripts/get.sh if not)
#
# Steps 1–3 run inside the ddev web container so PHP / Composer / Node match
# the versions the test pipeline uses. Run from the host:
#
#   bash scripts/setup.sh
#   composer setup           # same thing

set -uo pipefail

PASS="✅"
SKIP="⏭️ "
FAIL="❌"
PLUGIN_ABS="/var/www/html/wp-content/plugins/linkpurpose-wp"

results=()
record() { results+=("$1  $2"); }

in_ddev() { [ "${IS_DDEV_PROJECT:-}" = "true" ]; }

run_in_container() {
	if in_ddev; then
		( cd "$PLUGIN_ABS" && "$@" )
	else
		ddev exec -d "$PLUGIN_ABS" "$@"
	fi
}

if ! in_ddev; then
	if ! command -v ddev >/dev/null 2>&1; then
		echo "$FAIL  ddev is not installed on this host (https://ddev.com)." >&2
		exit 1
	fi
	if ! ddev describe >/dev/null 2>&1; then
		echo "$FAIL  ddev project is not running. Run 'ddev start' first." >&2
		exit 1
	fi
fi

echo "━━━ 1. composer install"
if run_in_container test -f vendor/bin/phpunit; then
	echo "$SKIP  vendor/ already populated."
	record "$SKIP" "composer install"
elif run_in_container composer install; then
	record "$PASS" "composer install"
else
	record "$FAIL" "composer install"
fi

echo "━━━ 2. composer install-test-suite"
if run_in_container mysql --user=root --password=root --host=db -e 'use linkpurpose_test' >/dev/null 2>&1; then
	echo "$SKIP  linkpurpose_test database already exists."
	record "$SKIP" "install-test-suite"
elif run_in_container composer install-test-suite; then
	record "$PASS" "install-test-suite"
else
	record "$FAIL" "install-test-suite"
fi

echo "━━━ 3. npm install"
if run_in_container test -d node_modules/vitest; then
	echo "$SKIP  node_modules/ already populated."
	record "$SKIP" "npm install"
elif run_in_container npm install; then
	record "$PASS" "npm install"
else
	record "$FAIL" "npm install"
fi

echo "━━━ 4. .vscode/settings.json"
cd "$(dirname "$0")/.."
if [ -f .vscode/settings.json ]; then
	echo "$SKIP  .vscode/settings.json exists — leaving your customizations alone."
	record "$SKIP" ".vscode/settings.json"
elif cp .vscode/settings.example.json .vscode/settings.json; then
	record "$PASS" ".vscode/settings.json"
else
	record "$FAIL" ".vscode/settings.json"
fi

echo "━━━ 5. assets/lib (vendored library)"
if [ -f assets/lib/js/linkpurpose.min.js ]; then
	echo "$SKIP  assets/lib populated. Refresh with: bash scripts/get.sh [ref]"
	record "$SKIP" "library bundle"
else
	echo "$FAIL  assets/lib is empty. Run: bash scripts/get.sh"
	record "$FAIL" "library bundle"
fi

echo ""
echo "Setup summary"
for r in "${results[@]}"; do echo "  $r"; done
for r in "${results[@]}"; do [[ $r == "$FAIL"* ]] && exit 1; done
echo ""
echo "Next: bash scripts/test.sh   (or composer test)"
