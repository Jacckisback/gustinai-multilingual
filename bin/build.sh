#!/usr/bin/env bash
# Builds the installable zip: dist/gustinai-multilingual-<version>.zip
# Usage: bin/build.sh
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(sed -n "s/^ \* Version: *//p" gustinai-multilingual.php | head -1)
php -l gustinai-multilingual.php >/dev/null
find includes -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
php tests/unit.php >/dev/null
mkdir -p dist
rm -f "dist/gustinai-multilingual-${version}.zip"
cd ..
zip -qr "gustinai-multilingual/dist/gustinai-multilingual-${version}.zip" gustinai-multilingual \
	-x "gustinai-multilingual/dist/*" "gustinai-multilingual/bin/*" "gustinai-multilingual/docs/*" \
	"gustinai-multilingual/tests/*" "gustinai-multilingual/.git*" "gustinai-multilingual/*.po~"
echo "Built gustinai-multilingual/dist/gustinai-multilingual-${version}.zip"
