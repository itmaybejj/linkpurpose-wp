#!/usr/bin/env bash
#
# Full quality gate. Every step runs even if an earlier one fails; a summary
# prints at the end and the exit code is non-zero if anything failed.
#
#   1. phpcbf           auto-fix (never fails the run)
#   2. phpcs            WordPress coding standard
#   3. phpmd            code-smell rules
#   4. phpunit          single-site
#   5. phpunit          multisite (WP_MULTISITE=1) — runs the network tests
#   6. cspell           spelling in PHP/JS/MD
#   7. eslint           hand-written JS
#   8. vitest           jsdom unit tests
#   9. wp plugin check  against the source tree with the dev-only files
#                       excluded (the same set .distignore drops at build
#                       time; `composer dist` is the authoritative artifact check)
#
# Invoked from the host, the script re-execs itself inside the ddev web
# container so php / composer / node / wp are the container's.

set -uo pipefail

PLUGIN_ABS="/var/www/html/wp-content/plugins/linkpurpose-wp"
if [ "${IS_DDEV_PROJECT:-}" != "true" ]; then
	if ! command -v ddev >/dev/null 2>&1; then
		echo "ddev is not installed; run this inside the container instead." >&2
		exit 1
	fi
	exec ddev exec -d "$PLUGIN_ABS" bash scripts/test.sh "$@"
fi
cd "$PLUGIN_ABS"

PASS="✅"
FAIL="❌"
results=()

run_step() {
	local label="$1"; shift
	echo ""
	echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
	echo "  $label"
	echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
	"$@"
	local code=$?
	if [ $code -eq 0 ]; then results+=("$PASS  $label"); else results+=("$FAIL  $label"); fi
	return $code
}

run_step "phpcbf (auto-fix)" ./vendor/bin/phpcbf
results[$(( ${#results[@]} - 1 ))]="$PASS  phpcbf (auto-fix)"

run_step "phpcs (WordPress)" ./vendor/bin/phpcs
run_step "phpmd" php -d error_reporting=24575 ./vendor/bin/phpmd src,linkpurpose.php,uninstall.php text ./phpmd.xml
run_step "phpunit" ./vendor/bin/phpunit
run_step "phpunit (multisite)" env WP_MULTISITE=1 ./vendor/bin/phpunit
run_step "cspell" npx --yes cspell --config cspell.json --no-progress \
	'linkpurpose.php' 'uninstall.php' 'src/**/*.php' 'assets/js/*.js' 'README.md' 'readme.txt' 'developers.md' 'AGENTS.md'
run_step "eslint" npx --no-install eslint assets/js/ tests/js/
run_step "vitest" npx --no-install vitest run

plugin_check() {
	if ! wp plugin is-installed plugin-check >/dev/null 2>&1; then
		echo "plugin-check plugin not installed; skipping (wp plugin install plugin-check --activate)."
		return 0
	fi
	wp plugin check linkpurpose-wp --slug=linkpurpose --severity=5 --error-severity=5 --include-experimental \
		--exclude-directories=vendor,node_modules,tests,scripts,build \
		--exclude-files=phpcs.xml.dist,phpmd.xml,phpunit.xml.dist,cspell.json,eslint.config.js,vitest.config.js,package.json,package-lock.json,composer.json,composer.lock,.distignore,.editorconfig,.gitignore,.phpunit.result.cache,developers.md,AGENTS.md,CLAUDE.md,temp-migration-plan.md
}
run_step "wp plugin check" plugin_check

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Summary"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
for r in "${results[@]}"; do echo "  $r"; done
echo ""
for r in "${results[@]}"; do [[ $r == "$FAIL"* ]] && exit 1; done
exit 0
