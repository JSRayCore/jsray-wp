#!/bin/sh
# Run the PHP suite against both ends of the supported range.
#
# "Requires at least: 6.0" and "Requires PHP: 7.4" are promises to every site
# that reads them. Testing only the newest WordPress leaves those numbers as
# guesses — this checks the floor as well as the ceiling.
#
#   sh tools/test-compat.sh
#   docker compose -f docker-compose.legacy.yml down -v    # tear the floor down
set -e
cd "$(dirname "$0")/.."

FAILED=0

run_suite() {
  label=$1
  compose=$2
  service=$3
  port=$4

  echo "==> $label"

  # shellcheck disable=SC2086
  docker compose $compose up -d >/dev/null 2>&1

  i=0
  until curl -sf -o /dev/null "http://localhost:$port/" \
     || curl -sf -o /dev/null "http://localhost:$port/wp-admin/install.php"; do
    i=$((i + 1))
    [ "$i" -gt 90 ] && { echo "   error: $label did not come up" >&2; FAILED=1; return; }
    sleep 1
  done

  # shellcheck disable=SC2086
  if ! docker compose $compose run --rm -T "$service" core is-installed >/dev/null 2>&1; then
    # shellcheck disable=SC2086
    docker compose $compose run --rm -T "$service" core install \
      --url="http://localhost:$port" --title="JSRay compat" \
      --admin_user=admin --admin_password=admin --admin_email=dev@example.com \
      --skip-email >/dev/null 2>&1
  fi

  # shellcheck disable=SC2086
  docker compose $compose run --rm -T "$service" plugin activate jsray >/dev/null 2>&1

  # shellcheck disable=SC2086
  version=$(docker compose $compose run --rm -T "$service" \
    eval 'echo get_bloginfo("version")." / PHP ".PHP_VERSION;' 2>/dev/null \
    | grep -vE "Container|level=warning" | tr -d '\r')
  echo "    WordPress $version"

  # shellcheck disable=SC2086
  output=$(docker compose $compose run --rm -T \
    -v "$(pwd)/tests/php/checks.php:/tmp/checks.php:ro" \
    "$service" eval-file /tmp/checks.php 2>&1 | grep -vE "Container|level=warning")

  echo "$output" | sed 's/^/    /'
  echo "$output" | grep -q "0 failed" || FAILED=1

  # A rendered page is where PHP notices surface; the suite alone will not show them.
  page=$(curl -s "http://localhost:$port/?p=$(docker compose $compose run --rm -T "$service" \
    post list --post_type=post --format=ids 2>/dev/null | grep -vE "Container|warning" | tr -d '\r' | tr ' ' '\n' | head -1)")
  notices=$(printf '%s' "$page" | grep -coE "(Warning|Notice|Fatal error|Deprecated):" || true)
  echo "    PHP notices on a rendered page: $notices"
  [ "$notices" = "0" ] || FAILED=1
}

run_suite "floor  — oldest supported" "-f docker-compose.legacy.yml" cli-legacy 8081
echo
run_suite "ceiling — current release" "" cli 8080

echo
if [ "$FAILED" = "1" ]; then
  echo "compatibility check FAILED" >&2
  exit 1
fi
echo "compatible across the declared range"
