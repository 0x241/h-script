#!/bin/sh
# Run from the local Compose root. Cached images only; disposable internal network.
set -eu
set +x
umask 077
: "${1:?installed application image required}" "${2:?cached database image required}"
: "${H_SCRIPT_FINANCIAL_BROWSER_IMAGE:?cached Playwright image required}"
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
. "$root/tests/gateway_http_artifacts.sh"
. "$root/tests/browser_fixtures.sh"
temporary=$(mktemp -d)
name="hscript-inbound-http-$(basename "$temporary" | tr '[:upper:].' '[:lower:]-')"
database="$name-db" application="$name-app" browser="$name-browser"
network_created=0 database_created=0 application_created=0 browser_created=0
cleanup() {
    if [ "$browser_created" = 1 ]; then docker rm -fv "$browser" >/dev/null; fi
    if [ "$application_created" = 1 ]; then docker rm -fv "$application" >/dev/null; fi
    if [ "$database_created" = 1 ]; then docker rm -fv "$database" >/dev/null; fi
    if [ "$network_created" = 1 ]; then docker network rm "$name" >/dev/null; fi
    rm -rf -- "$temporary"
}
trap cleanup EXIT HUP INT TERM
for image in "$1" "$2" "$H_SCRIPT_FINANCIAL_BROWSER_IMAGE"; do docker image inspect "$image" >/dev/null; done
application_platform=$(docker image inspect "$1" --format '{{.Os}}/{{.Architecture}}')
cat > "$temporary/inbound-http.config.cjs" <<'EOF'
const {defineConfig}=require('@playwright/test');
module.exports=defineConfig({testDir:'.',testMatch:'inbound-http.spec.cjs',outputDir:'/tmp/inbound-http-results',reporter:'line',workers:1,retries:0,timeout:60000,use:{baseURL:'http://fixture.invalid',trace:'off',screenshot:'off',video:'off'},projects:[{name:'chromium',use:{browserName:'chromium'}}]});
EOF
cat > "$temporary/gateway-capture-pool.conf" <<'EOF'
[www]
php_admin_value[auto_prepend_file] = /fixture/gateway_http_audit_prepend.php
EOF
docker network create --internal "$name" >/dev/null
network_created=1
docker create --name "$database" --network "$name" --network-alias database --tmpfs /var/lib/mysql:rw,size=512m \
    --env MYSQL_ROOT_PASSWORD=synthetic-login-root --env MYSQL_DATABASE=fixture \
    --env MYSQL_USER=fixture --env MYSQL_PASSWORD=synthetic-login-password "$2" >/dev/null
database_created=1
docker start "$database" >/dev/null
docker create --platform "$application_platform" --name "$application" --network "$name" --network-alias fixture.invalid --entrypoint /bin/sh -w /var/www/html \
    --env H_SCRIPT_GATEWAY_HTTP_CAPTURE=1 --env H_SCRIPT_FINANCIAL_TEST=1 \
    --env APP_DOMAIN=fixture.invalid --env APP_ENV=development --env REDIS_ENABLED=0 \
    --env DB_HOST=database:3306 --env DB_NAME=fixture --env DB_USER=fixture --env DB_PASSWORD=synthetic-login-password \
    --env APP_DATA_KEY=synthetic-login-key --env CONFIGURATOR_PASSWORD=synthetic-login-configurator \
    --env INSTALL_ADMIN_PASSWORD=synthetic-login-admin --env INSTALL_ADMIN_SECRET_ANSWER=fixture --env INSTALL_ADMIN_PIN=123456 \
    --env TELEMETRY_ENDPOINT=http://127.0.0.1:9/api/v1/installations --env RECOVERY_ENABLED=0 \
    --env CRON_TOKEN=synthetic-isolated-cron-token-00000001 \
    -v "$temporary/gateway-capture-pool.conf:/usr/local/etc/php-fpm.d/zz-gateway-capture.conf:ro" \
    -v "$root/tests/fixtures/gateway_http_audit_prepend.php:/fixture/gateway_http_audit_prepend.php:ro" \
    -v "$root/tests:/var/www/html/tests:ro" \
    "$1" -eu -c '
        if [ ! -f /tmp/inbound-http-installed ]; then
            attempt=0
            until php -r '\''require "vendor/autoload.php"; $db=new HScript\Database\Connection(); exit($db->open(getenv("DB_HOST"),getenv("DB_NAME"),getenv("DB_USER"),getenv("DB_PASSWORD"))?0:1);'\''; do
                attempt=$((attempt + 1)); test "$attempt" -lt 120; sleep 1
            done
            php /usr/local/share/hscript/write-config.php
            APP_AUTO_INSTALL=1 php /usr/local/share/hscript/install-db.php
            php tests/fixtures/financial_http_setup.php
            php tests/fixtures/inbound_http_setup.php --setup
            php tests/test_access.php
            touch /tmp/inbound-http-installed
        fi
        export APP_AUTO_INSTALL=0 APP_GENERATE_CONFIG=0
        exec docker-entrypoint-hscript httpd -DFOREGROUND -f /etc/apache2/httpd.conf
    ' >/dev/null
application_created=1
wait_application() {
    attempt=0
    until docker exec "$application" curl -fsS --max-time 2 -H 'Host: fixture.invalid' http://127.0.0.1/login >/dev/null 2>&1; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 180 ] || [ "$(docker inspect "$application" --format '{{.State.Running}}')" != true ]; then docker logs --tail 60 "$application"; exit 1; fi
        sleep 1
    done
}
run_browser() {
    phase=$1
    docker create --name "$browser" --network "$name" --init --shm-size=1g \
        -v "$root/tests/browser/inbound-http.spec.cjs:/tests/inbound-http.spec.cjs:ro" \
    -v "$root/tests/browser/capture-callbacks.cjs:/tests/capture-callbacks.cjs:ro" \
        "$H_SCRIPT_FINANCIAL_BROWSER_IMAGE" npx --no-install playwright test --config=inbound-http.config.cjs --grep "$phase" >/dev/null
    browser_created=1
    browser_restore_fixture "$browser" "$temporary/inbound-http.config.cjs" /tests/inbound-http.config.cjs
    if [ -n "${inbound_original_file:-}" ]; then
        browser_restore_fixture "$browser" "$inbound_original_file" /tmp/inbound-http-original.json
    fi
    docker start -a "$browser" || :
    test "$(docker inspect "$browser" --format '{{.State.ExitCode}}')" = 0
}
docker start "$application" >/dev/null
wait_application
gateway_capture_begin "$1"
docker logs --tail 12 "$application"
run_browser invoice
docker exec "$application" php tests/fixtures/inbound_http_setup.php --verify invoice
gateway_capture_phase invoice "$browser"
docker exec "$application" php tests/fixtures/inbound_http_setup.php --policy
docker cp "$browser:/tmp/inbound-http-original.json" "$temporary/original.json"
docker rm -fv "$browser" >/dev/null; browser_created=0
inbound_original_file="$temporary/original.json"
run_browser settlement
docker exec "$application" php tests/fixtures/inbound_http_setup.php --verify settled
gateway_capture_phase settled "$browser"
docker rm -fv "$browser" >/dev/null; browser_created=0
docker restart "$application" >/dev/null
wait_application
run_browser restart
docker exec "$application" php tests/fixtures/inbound_http_setup.php --verify restart
gateway_capture_phase restart "$browser"
echo 'Installed-candidate Inbound fee isolated HTTP suite passed, including restart.'
