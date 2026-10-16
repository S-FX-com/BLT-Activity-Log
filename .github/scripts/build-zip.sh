#!/usr/bin/env bash
# Build the distribution zip: one stable top-level blt-activity-log/ folder,
# trimmed by .distignore. Usage: build-zip.sh <version>
# Prints the zip path on the last line.
set -euo pipefail

VERSION="${1:?usage: build-zip.sh <version>}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

rm -rf build
mkdir -p build/blt-activity-log
rsync -a --exclude-from=.distignore --exclude=.git --exclude=.github ./ build/blt-activity-log/
( cd build && zip -rq "blt-activity-log-${VERSION}.zip" blt-activity-log )
echo "build/blt-activity-log-${VERSION}.zip"
