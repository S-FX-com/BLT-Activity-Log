#!/usr/bin/env bash
# Verify a built distribution zip. Usage: verify-zip.sh <version>
# Fails when the vendored update checker is missing, when dev files or a
# worker/ folder ship, or when the packaged header carries another version.
set -euo pipefail

VERSION="${1:?usage: verify-zip.sh <version>}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
ZIP="$ROOT/build/blt-activity-log-${VERSION}.zip"
LIST="$(unzip -l "$ZIP")"

# Single stable top-level folder, main file at its root.
echo "$LIST" | awk '{print $4}' | grep -q '^blt-activity-log/blt-activity-log\.php$'
if unzip -Z1 "$ZIP" | grep -v '^blt-activity-log/' | grep -q .; then
	echo "Zip has entries outside blt-activity-log/" >&2
	exit 1
fi

# The update checker's internal vendor dir must ship, along with the family layer.
echo "$LIST" | grep -q 'blt-activity-log/includes/lib/plugin-update-checker/vendor/Parsedown'
echo "$LIST" | grep -q 'blt-activity-log/includes/blt-family/bootstrap\.php'

# Dev-only files and folders must not ship.
if echo "$LIST" | grep -qE 'blt-activity-log/(tests|tasks|vendor|bin|worker|\.github)/'; then
	echo "Dev-only folder found in zip" >&2
	exit 1
fi
if echo "$LIST" | grep -qE 'blt-activity-log/(composer\.json|phpcs\.xml\.dist|phpunit\.xml\.dist|CLAUDE\.md|AGENTS\.md|DESIGN\.md|CONVENTIONS\.md|HOOKS\.md|blt-family-smoke\.php)$'; then
	echo "Dev-only file found in zip" >&2
	exit 1
fi

# The packaged header must carry the released version.
unzip -p "$ZIP" blt-activity-log/blt-activity-log.php | grep -q "^ \* Version:[[:space:]]*${VERSION}\$"
unzip -p "$ZIP" blt-activity-log/blt-activity-log.php | grep -q "define( 'BLT_ACTIVITY_LOG_VERSION', '${VERSION}' );"
echo "Zip OK: ${ZIP}"
