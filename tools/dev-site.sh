#!/bin/sh
# Bring up a local WordPress with the plugin activated and test content seeded.
#
#   sh tools/dev-site.sh          → http://localhost:8080 (admin / admin)
#   docker compose down -v        → tear it all down
#
# Idempotent: safe to re-run against an already-installed site.
set -e
cd "$(dirname "$0")/.."

if ! docker compose version >/dev/null 2>&1; then
  echo "error: Docker Compose not available — install Docker Desktop first." >&2
  exit 1
fi

echo "==> starting containers"
docker compose up -d db wordpress

echo "==> waiting for WordPress to answer"
i=0
until curl -sf -o /dev/null http://localhost:8080/wp-admin/install.php; do
  i=$((i + 1))
  if [ "$i" -gt 60 ]; then
    echo "error: WordPress did not come up within 60s" >&2
    docker compose logs --tail 30 wordpress >&2
    exit 1
  fi
  sleep 1
done

cli() {
  docker compose run --rm -T cli "$@"
}

if cli core is-installed >/dev/null 2>&1; then
  echo "==> WordPress already installed"
else
  echo "==> installing WordPress"
  cli core install \
    --url=http://localhost:8080 \
    --title="JSRay Dev" \
    --admin_user=admin \
    --admin_password=admin \
    --admin_email=dev@example.com \
    --skip-email
fi

echo "==> updating WordPress to the current release"
cli core update --minor=false >/dev/null 2>&1 || cli core update >/dev/null 2>&1 || true
cli core update-db >/dev/null 2>&1 || true
echo "    now on WordPress $(cli core version | tr -d "\r")"

echo "==> activating the plugin"
cli plugin activate jsray

echo "==> seeding a test post covering every rendering path"
if [ -z "$(cli post list --post_type=post --name=jsray-test --field=ID)" ]; then
  cli post create \
    --post_type=post \
    --post_status=publish \
    --post_title="JSRay test" \
    --post_name=jsray-test \
    --post_content='<!-- wp:jsray/code {"code":"const MAX_RETRIES = 3;\n\nasync function fetchUser(id) {\n  const res = await fetch(`/api/users/${id}`); // see https://jsray.org\n  return res.json();\n}","language":"js","filename":"app.js","showLineNumbers":true} -->
<!-- /wp:jsray/code -->

<!-- wp:jsray/code {"code":"class Greeter:\n    def __init__(self, name):\n        self.name = name\n\n    def greet(self):\n        print(f\"hello {self.name}\")","language":"python","align":"wide"} -->
<!-- /wp:jsray/code -->

<!-- wp:code {"className":"language-sql"} -->
<pre class="wp-block-code language-sql"><code>SELECT * FROM wp_posts WHERE post_status = &#39;publish&#39;;</code></pre>
<!-- /wp:code -->

<!-- wp:code -->
<pre class="wp-block-code"><code>def greet(name):
    print(f"hello {name}")</code></pre>
<!-- /wp:code -->

<!-- wp:shortcode -->
[jsray lang="go"]
func main() { fmt.Println("hi") }
[/jsray]
<!-- /wp:shortcode -->'
else
  echo "   (test post already exists)"
fi

echo
echo "test post: http://localhost:8080/?p=$(cli post list --post_type=post --name=jsray-test --field=ID | tr -d '\r')"
echo "site:   http://localhost:8080"
echo "admin:  http://localhost:8080/wp-admin  (admin / admin)"
echo "config: http://localhost:8080/wp-admin/options-general.php?page=jsray"
