#!/bin/sh
# JSRay WordPress · sync bundled Core assets from the Core repo.
#
# The plugin bundles a snapshot of JSRay Core's dist/. This script refreshes
# that snapshot and updates bundledCore.version in version.json so the two
# never drift silently.
#
# Usage:
#   sh tools/sync-core.sh                            # Core repo at ../jsray
#   JSRAY_CORE_DIR=/path/to/jsray sh tools/sync-core.sh
#   JSRAY_CORE_VERSION=0.0.1-beta.3 sh tools/sync-core.sh   # from npm, for CI
set -e
cd "$(dirname "$0")/.."

# Where the Core files come from.
#
# A sibling checkout is what a maintainer has locally, and is what lets
# sync-integrations.sh exercise an unreleased Core. No CI runner has one, so
# reading only a checkout meant nothing automatic could ever apply a Core fix —
# which is why a published fix could sit unpropagated here. Unpacking the
# published tarball gives the same files: Core publishes the dist and the
# palette sources that get vendored.
if [ -n "$JSRAY_CORE_VERSION" ]; then
  CORE_TMP=$(mktemp -d)
  trap 'rm -rf "$CORE_TMP"' EXIT
  ( cd "$CORE_TMP" && npm pack "@jsray/core@$JSRAY_CORE_VERSION" >/dev/null && tar xzf ./*.tgz )
  CORE_DIR="$CORE_TMP/package"
  echo "source: npm @jsray/core@$JSRAY_CORE_VERSION"
else
  CORE_DIR="${JSRAY_CORE_DIR:-../jsray}"
  echo "source: checkout $CORE_DIR"
fi
CORE_DIST="$CORE_DIR/dist"

if [ ! -d "$CORE_DIST" ]; then
  echo "error: Core dist not found at $CORE_DIST" >&2
  echo "       set JSRAY_CORE_DIR to the JSRay Core repo root." >&2
  exit 1
fi

for f in jsray.js jsray.css themes/default.css; do
  if [ ! -f "$CORE_DIST/$f" ]; then
    echo "error: missing $CORE_DIST/$f — run 'sh build.sh' in Core first." >&2
    exit 1
  fi
done

cp "$CORE_DIST/jsray.js"   assets/js/jsray.js
cp "$CORE_DIST/jsray.css"  assets/css/jsray.css

# Every Core palette ships with the plugin, so the settings screen can offer
# the same four themes the rest of the ecosystem has.
mkdir -p assets/css/themes
rm -f assets/css/themes/*.css
cp "$CORE_DIST"/themes/*.css assets/css/themes/

# The token vocabulary travels with the snapshot: it is what the custom-palette
# validator checks user input against, so it must describe the bundled Core.
cp "$CORE_DIR/vocabulary.json" vocabulary.json

# Sync bundledCore.version in version.json from the Core version.json.
if command -v node >/dev/null 2>&1; then
  node tools/sync-core-version.mjs "$CORE_DIR"
else
  echo "warn: node not found — assets copied, but bundledCore.version not updated." >&2
fi

echo "synced Core dist ($CORE_DIST) → plugin assets/"
