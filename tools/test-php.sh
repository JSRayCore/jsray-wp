#!/bin/sh
# Run tests/php/checks.php inside the docker-compose WordPress.
#
# The node suites check the plugin as text; this checks it as running code.
# Requires the dev site: npm run dev:site
set -e
cd "$(dirname "$0")/.."

if ! docker compose ps --status running --services 2>/dev/null | grep -q wordpress; then
  echo "error: the dev site is not running — start it with 'npm run dev:site'." >&2
  exit 1
fi

docker compose run --rm -T \
  -v "$(pwd)/tests/php/checks.php:/tmp/jsray-checks.php:ro" \
  cli eval-file /tmp/jsray-checks.php 2>&1 | grep -v 'Container '
