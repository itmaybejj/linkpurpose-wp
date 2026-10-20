#!/usr/bin/env bash
#
# Build the WordPress.org-shape distribution zip.
#
#   build/linkpurpose-wp.zip            stable name
#   build/linkpurpose-wp-<version>.zip  version-stamped copy
#
# Uses `wp dist-archive` (honors .distignore). The plugin ships its own
# autoloader, so no vendor/ tree is needed in the archive and no
# `composer install --no-dev` dance is required. After archiving, the zip
# is walked for dev-only paths and the build fails if any leaked.
#
# Run inside the ddev container:
#   ddev exec -d /var/www/html/wp-content/plugins/linkpurpose-wp composer dist

set -euo pipefail

cd "$(dirname "$0")/.."
SLUG="linkpurpose-wp"
DIST_DIR="${DIST_DIR:-./build}"
PASS="✅"
FAIL="❌"

VERSION=$(grep -E '^\s*\*\s*Version:' linkpurpose.php | head -1 | sed -E 's/.*Version:\s*([^[:space:]]+).*/\1/')
CONST_VERSION=$(grep -E "const VERSION = '" src/Plugin.php | sed -E "s/.*'([^']+)'.*/\1/")
if [ "$VERSION" != "$CONST_VERSION" ]; then
	echo "$FAIL  Version header ($VERSION) and Plugin::VERSION ($CONST_VERSION) differ." >&2
	exit 1
fi

if ! wp cli has-command dist-archive >/dev/null 2>&1; then
	echo "Installing wp-cli/dist-archive-command…"
	wp package install wp-cli/dist-archive-command:^2.0 2>&1 | grep -viE 'Deprecated|implicitly marking|nullable type' || true
fi

mkdir -p "$DIST_DIR"
OUT_ABS="$(cd "$DIST_DIR" && pwd)/$SLUG.zip"
rm -f "$OUT_ABS"
wp dist-archive . "$OUT_ABS" --create-target-dir

FORBIDDEN=(
	'/tests/' '/scripts/' '/vendor/' '/node_modules/' '/build/'
	'/\.git' '/\.distignore' '/\.editorconfig' '/\.vscode' '/\.agents/' '/\.claude/'
	'/AGENTS\.md$' '/CLAUDE\.md$' '/developers\.md$' '/temp-' '/TEMP-'
	'/phpcs\.xml' '/phpmd\.xml' '/phpunit\.xml' '/cspell\.json' '/eslint\.config\.js' '/vitest\.config\.js'
	'/package(-lock)?\.json$' '/composer\.(json|lock)$'
)
contents=$(unzip -l "$OUT_ABS" | awk 'NR>3 {print $NF}')
leaks=0
for pattern in "${FORBIDDEN[@]}"; do
	if echo "$contents" | grep -E "$pattern" >/dev/null 2>&1; then
		echo "$FAIL  Leaked: $pattern"
		echo "$contents" | grep -E "$pattern" | sed 's/^/      /'
		leaks=1
	fi
done
if [ "$leaks" -ne 0 ]; then
	echo "$FAIL  Update .distignore and re-run."
	exit 1
fi
cp -f "$OUT_ABS" "$(dirname "$OUT_ABS")/$SLUG-$VERSION.zip"
echo "$PASS  $OUT_ABS ($VERSION)"
unzip -l "$OUT_ABS" | awk 'NR>3 && $NF ~ /\// {print $NF}' | awk -F/ '{print $1"/"$2}' | sort -u
