#!/usr/bin/env bash
# The plugin header, the BLT_ACTIVITY_LOG_VERSION constant and the readme.txt
# stable tag must carry one version. CI cuts releases; nobody bumps by hand.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

HEADER="$(sed -n 's/^ \* Version:[[:space:]]*\([0-9][0-9.]*\)$/\1/p' blt-activity-log.php)"
CONST="$(sed -n "s/^define( 'BLT_ACTIVITY_LOG_VERSION', '\([0-9][0-9.]*\)' );.*/\1/p" blt-activity-log.php)"
STABLE="$(sed -n 's/^Stable tag:[[:space:]]*\([0-9][0-9.]*\)$/\1/p' readme.txt)"

echo "header=${HEADER} constant=${CONST} stable_tag=${STABLE}"

if [ -z "$HEADER" ] || [ "$HEADER" != "$CONST" ] || [ "$HEADER" != "$STABLE" ]; then
	echo "Version mismatch: header, BLT_ACTIVITY_LOG_VERSION and readme.txt must agree." >&2
	exit 1
fi
