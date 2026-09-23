#!/bin/sh
# Fresh private database + copied source only; no live configuration/data is mounted.
set -eu
set +x
: "${1:?PHP Apache image (Debian, apache2-foreground) required}" "${2:?MySQL image required}"
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
temporary=$(mktemp -d)
name="hscript-seo-$(basename "$temporary" | tr '[:upper:].' '[:lower:]-')"
app="$name-app"
database="$name-db"
proxy="$name-proxy"
proxy_created=0
network_created=0
app_created=0
database_created=0
cleanup() {
    if [ "$proxy_created" = 1 ]; then docker rm -fv "$proxy" >/dev/null; fi
    if [ "$app_created" = 1 ]; then docker rm -fv "$app" >/dev/null; fi
    if [ "$database_created" = 1 ]; then docker rm -fv "$database" >/dev/null; fi
    if [ "$network_created" = 1 ]; then docker network rm "$name" >/dev/null; fi
    rm -rf -- "$temporary"
}
trap cleanup EXIT HUP INT TERM
# Explicit allowlist excludes live _config*, user uploads, logs, sessions and private data.
COPYFILE_DISABLE=1 tar --no-xattrs --exclude=module/_config/pass -cf "$temporary/source.tar" -C "$root" \
    .htaccess rw.php _dbstru.php system-page.html VERSION SCHEMA_VERSION favicon.svg favicon.ico \
    vendor src lib module tpl lang static migrations resources bin tests docker/runtime

docker network create --internal "$name" >/dev/null
network_created=1
subnet=$(docker network inspect "$name" --format '{{(index .IPAM.Config 0).Subnet}}')
docker create --name "$database" --network "$name" --network-alias database \
    --tmpfs /var/lib/mysql:rw,size=1g \
    -e MYSQL_ROOT_PASSWORD=synthetic-seo-root -e MYSQL_DATABASE=fixture \
    -e MYSQL_USER=fixture -e MYSQL_PASSWORD=synthetic-seo-password "$2" >/dev/null
database_created=1
docker start "$database" >/dev/null
docker create --name "$app" --network "$name" --network-alias fixture.invalid --network-alias origin \
    --entrypoint sleep -w /var/www/html \
    -e H_SCRIPT_SEO_TEST=1 -e APP_ENV=local -e APP_DOMAIN=fixture.invalid \
    -e "TRUSTED_PROXY_CIDRS=$subnet" -e REDIS_ENABLED=0 -e APP_AUTO_INSTALL=0 -e APP_DATA_KEY=synthetic-seo-key \
    -e DB_HOST=database:3306 -e DB_NAME=fixture -e DB_USER=fixture -e DB_PASSWORD=synthetic-seo-password \
    -e CONFIGURATOR_PASSWORD=synthetic-seo-configurator -e INSTALL_ADMIN_PASSWORD=synthetic-seo-admin \
    -e INSTALL_ADMIN_SECRET_ANSWER=fixture -e INSTALL_ADMIN_PIN=123456 \
    -e TELEMETRY_ENDPOINT=http://127.0.0.1:9/api/v1/installations -e RECOVERY_ENABLED=0 \
    "$1" infinity >/dev/null
app_created=1
docker start "$app" >/dev/null
docker cp "$temporary/source.tar" "$app:/tmp/source.tar"
docker exec "$app" sh -eu -c '
    tar -xf /tmp/source.tar -C /var/www/html
    mkdir -p logs backup compile tpl_c upload .cfg
    chown -R www-data:www-data /var/www/html
    php docker/runtime/write-config.php
    attempt=0
    until php -r '\''require "vendor/autoload.php"; $db=new HScript\Database\Connection(); exit($db->open(getenv("DB_HOST"),"fixture",getenv("DB_USER"),getenv("DB_PASSWORD")) ? 0 : 1);'\''; do
        attempt=$((attempt + 1)); test "$attempt" -lt 120; sleep 1
    done
    APP_AUTO_INSTALL=1 php docker/runtime/install-db.php
    php tests/fixtures/seo_setup.php en,fr 0
    a2enmod rewrite >/dev/null
    printf "%s\n" "ServerName fixture.invalid" "<Directory /var/www/html>" "AllowOverride All" "Require all granted" "</Directory>" > /etc/apache2/conf-enabled/seo-fixture.conf
'
docker exec -d "$app" apache2-foreground
attempt=0
until docker exec "$app" curl -fsS -H 'Host: fixture.invalid' http://127.0.0.1/login >/dev/null 2>&1; do
    attempt=$((attempt + 1)); test "$attempt" -lt 60; sleep 1
done
docker exec "$app" php tests/seo_acceptance_http.php http://127.0.0.1 fixture.invalid en,fr
docker exec "$app" php tests/fixtures/seo_setup.php en,fr 17
docker exec "$app" php tests/seo_acceptance_http.php http://127.0.0.1 fixture.invalid en,fr
# Move the same installation to a real nested document root; Apache handles .htaccess.
docker exec "$app" sh -eu -c '
    php tests/fixtures/seo_setup.php fr,en 17
    mkdir /tmp/seo-site
    cp -a /var/www/html/. /tmp/seo-site/
    mkdir -p /var/www/html/nested/cms
    cp -a /tmp/seo-site/. /var/www/html/nested/cms/
    chown -R www-data:www-data /var/www/html/nested
    # The root installation must not intercept nested URLs via its parent rewrite rules.
    mv /var/www/html/.htaccess /tmp/seo-root.htaccess
'
docker exec -w /var/www/html/nested/cms "$app" php tests/seo_acceptance_http.php http://127.0.0.1/nested/cms fixture.invalid fr,en
docker exec -w /var/www/html/nested/cms "$app" php tests/fixtures/seo_setup.php fr,en 17 1
# With HTTPS enforcement enabled, trusted TLS termination must not redirect to itself.
# TLS termination uses a fixture-specific certificate and the private network as trusted proxy scope.
openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
    -subj /CN=seo-fixture.invalid -addext subjectAltName=DNS:seo-fixture.invalid \
    -keyout "$temporary/tls.key" -out "$temporary/tls.crt" >/dev/null 2>&1
cat > "$temporary/nginx.conf" <<'CONF'
events {}
http {
    proxy_cache_path /tmp/seo-cache keys_zone=seo:1m;
    server {
        listen 443 ssl;
        ssl_certificate /fixture/tls.crt;
        ssl_certificate_key /fixture/tls.key;
        location / {
            proxy_pass http://origin;
            proxy_set_header Host $host;
            proxy_set_header X-Forwarded-Proto https;
            proxy_cache seo;
            proxy_cache_key "$scheme$host$request_uri";
            proxy_cache_valid 200 10m;
            add_header X-SEO-Cache $upstream_cache_status always;
        }
    }
}
CONF
docker cp "$temporary/tls.crt" "$app:/tmp/seo-fixture.crt"
docker create --name "$proxy" --network "$name" --network-alias seo-fixture.invalid \
    -v "$temporary:/fixture:ro" -v "$temporary/nginx.conf:/etc/nginx/nginx.conf:ro" \
    nginx:1.29.1-alpine >/dev/null
proxy_created=1
docker start "$proxy" >/dev/null
attempt=0
until docker exec "$app" curl -fsS --cacert /tmp/seo-fixture.crt https://seo-fixture.invalid/nested/cms/login >/dev/null 2>&1; do
    attempt=$((attempt + 1)); test "$attempt" -lt 30; sleep 1
done
docker exec -w /var/www/html/nested/cms -e SEO_TEST_CA_FILE=/tmp/seo-fixture.crt "$app" \
    php tests/seo_acceptance_http.php https://seo-fixture.invalid/nested/cms seo-fixture.invalid fr,en
echo 'Isolated SEO suite passed: empty/populated DB, changed primary, nested .htaccess and verified HTTPS proxy.'
