#!/usr/bin/env bash
# Builds the installable zip: dist/gustinai-multilingual-<version>.zip
# Usage: bin/build.sh
set -euo pipefail
cd "$(dirname "$0")/.."
root=$(pwd)
version=$(sed -n "s/^ \* Version: *//p" gustinai-multilingual.php | head -1)
php -l gustinai-multilingual.php >/dev/null
find includes -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
php tests/unit.php >/dev/null
# The zip always holds a "gustinai-multilingual" folder, whatever this checkout is called.
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir "$stage/gustinai-multilingual"
tar -cf - --exclude=./dist --exclude=./bin --exclude=./docs --exclude=./tests --exclude='./.git*' --exclude='*.po~' . \
	| tar -xf - -C "$stage/gustinai-multilingual"
mkdir -p dist
rm -f "dist/gustinai-multilingual-${version}.zip"
( cd "$stage" && zip -qr "$root/dist/gustinai-multilingual-${version}.zip" gustinai-multilingual )
echo "Built dist/gustinai-multilingual-${version}.zip"
