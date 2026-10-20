#!/usr/bin/env bash
#
# Pull the specified ref of the Link Purpose JS library from GitHub into
# assets/lib/. Never hand-edit assets/lib/ — changes belong upstream at
# https://github.com/itmaybejj/linkpurpose and are pulled in via this script.
#
# Run from the plugin root. Clones over SSH, so your key must be available
# (on the host, or forwarded into the ddev container).

set -euo pipefail

GIT_REF="${1:-main}"

cd "$(dirname "$0")/.."
rm -rf tmp
mkdir -p tmp
git clone --quiet git@github.com:itmaybejj/linkpurpose.git tmp
git -C tmp checkout --quiet "$GIT_REF"
rm -rf assets/lib/js assets/lib/css
mkdir -p assets/lib
mv tmp/js assets/lib/js
mv tmp/css assets/lib/css
rm -rf tmp

echo "assets/lib refreshed from itmaybejj/linkpurpose@$GIT_REF"
grep -o "LinkPurpose.version = '[^']*'" assets/lib/js/linkpurpose.js || true
