#!/bin/sh
# JSRay WordPress · package script
# Builds build/jsray-wp-<version>.zip with a top-level jsray/ folder.
set -e

cd "$(dirname "$0")/.."
ROOT=$(pwd)
VERSION=$(sed -n "s/^.*Version: \([0-9][0-9A-Za-z._-]*\).*$/\1/p" jsray.php | head -n 1)

if [ -z "$VERSION" ]; then
  echo "error: could not read plugin version from jsray.php" >&2
  exit 1
fi

if ! command -v zip >/dev/null 2>&1; then
  echo "error: zip command not found" >&2
  exit 1
fi

# Gate packaging on version metadata (and, when Core is available, bundle drift).
# CI runs the same check on every push; this makes a local zip just as safe.
if command -v node >/dev/null 2>&1; then
  JSRAY_STRICT_DRIFT=1 node tools/check-versions.mjs
else
  echo "warn: node not found — skipping version metadata check." >&2
fi

OUT="build/jsray-wp-$VERSION.zip"
TMPDIR="${TMPDIR:-/tmp}/jsray-wp-zip.$$"

rm -rf "$TMPDIR"
mkdir -p "$TMPDIR/jsray" build

cp jsray.php uninstall.php block.json readme.txt LICENSE "$TMPDIR/jsray/"
# Data files the plugin reads at runtime: the digests it verifies the bundled
# Core against, and the token vocabulary it validates custom palettes with.
cp core-integrity.json vocabulary.json "$TMPDIR/jsray/"
cp -R assets "$TMPDIR/jsray/"
if [ -d languages ]; then
  cp -R languages "$TMPDIR/jsray/"
fi

find "$TMPDIR" -name .DS_Store -delete
rm -f "$OUT"
(cd "$TMPDIR" && zip -qr "$ROOT/$OUT" jsray)

rm -rf "$TMPDIR"
echo "built $OUT"
