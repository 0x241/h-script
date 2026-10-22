#!/bin/sh
# Run from the local Compose root; every database/container is disposable and uniquely named.
set -eu
set +x
umask 077
: "${1:?application image required}" "${2:?database image required}"
: "${H_SCRIPT_FINANCIAL_BROWSER_IMAGE:?cached Playwright browser image required}"
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
. "$root/tests/gateway_http_artifacts.sh"
. "$root/tests/browser_fixtures.sh"
temporary=$(mktemp -d)
name="hscript-financial-http-$(basename "$temporary" | tr '[:upper:].' '[:lower:]-')"
database="$name-db"
application="$name-app"
browser="$name-browser"
restart_browser="$name-restart-browser"
network_created=0 database_created=0 application_created=0 browser_created=0 restart_browser_created=0
cleanup() {
    if [ "$restart_browser_created" = 1 ]; then docker rm -fv "$restart_browser" >/dev/null; fi
    if [ "$browser_created" = 1 ]; then docker rm -fv "$browser" >/dev/null; fi
    if [ "$application_created" = 1 ]; then docker rm -fv "$application" >/dev/null; fi
    if [ "$database_created" = 1 ]; then docker rm -fv "$database" >/dev/null; fi
    if [ "$network_created" = 1 ]; then docker network rm "$name" >/dev/null; fi
    rm -rf -- "$temporary"
}
trap cleanup EXIT HUP INT TERM
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
docker create --name "$application" --network "$name" --network-alias fixture.invalid --entrypoint /bin/sh -w /var/www/html \
    --env H_SCRIPT_GATEWAY_HTTP_CAPTURE=1 --env H_SCRIPT_FINANCIAL_TEST=1 --env APP_DOMAIN=fixture.invalid --env APP_ENV=development --env REDIS_ENABLED=0 \
    --env DB_HOST=database:3306 --env DB_NAME=fixture --env DB_USER=fixture --env DB_PASSWORD=synthetic-login-password \
    --env APP_DATA_KEY=synthetic-login-key --env CONFIGURATOR_PASSWORD=synthetic-login-configurator \
    --env INSTALL_ADMIN_PASSWORD=synthetic-login-admin --env INSTALL_ADMIN_SECRET_ANSWER=fixture --env INSTALL_ADMIN_PIN=123456 \
    --env TELEMETRY_ENDPOINT=http://127.0.0.1:9/api/v1/installations --env RECOVERY_ENABLED=0 \
    --env CRON_TOKEN=synthetic-isolated-cron-token-00000001 \
    -v "$temporary/gateway-capture-pool.conf:/usr/local/etc/php-fpm.d/zz-gateway-capture.conf:ro" \
    -v "$root/tests/fixtures/gateway_http_audit_prepend.php:/fixture/gateway_http_audit_prepend.php:ro" \
    -v "$root/tests:/var/www/html/tests:ro" "$1" -eu -c '
        if [ ! -f /tmp/financial-http-installed ]; then
            attempt=0
            until php -r '\''require "vendor/autoload.php"; $db=new HScript\Database\Connection(); exit($db->open(getenv("DB_HOST"),getenv("DB_NAME"),getenv("DB_USER"),getenv("DB_PASSWORD"))?0:1);'\''; do
                attempt=$((attempt + 1)); test "$attempt" -lt 120; sleep 1
            done
            php /usr/local/share/hscript/write-config.php
            APP_AUTO_INSTALL=1 php /usr/local/share/hscript/install-db.php
            php tests/fixtures/financial_http_setup.php
            touch /tmp/financial-http-installed
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
docker start "$application" >/dev/null
wait_application
gateway_capture_begin "$1"
docker logs --tail 60 "$application"
docker create --name "$browser" --network "$name" --init --shm-size=1g \
    -v "$root/tests/browser/financial-idempotency.config.cjs:/tests/financial-idempotency.config.cjs:ro" \
    -v "$root/tests/browser/financial-idempotency.spec.cjs:/tests/financial-idempotency.spec.cjs:ro" \
    -v "$root/tests/browser/capture-callbacks.cjs:/tests/capture-callbacks.cjs:ro" \
    "$H_SCRIPT_FINANCIAL_BROWSER_IMAGE" npx --no-install playwright test --config=financial-idempotency.config.cjs --grep-invert restart >/dev/null
browser_created=1
docker start -a "$browser" || :
test "$(docker inspect "$browser" --format '{{.State.ExitCode}}')" = 0
docker exec "$application" php tests/fixtures/financial_http_verify.php
gateway_capture_phase settlement "$browser"
docker cp "$browser:/tmp/financial-original.json" "$temporary/original.json"
docker cp "$browser:/tmp/financial-webmoney-original.json" "$temporary/webmoney-original.json"
docker cp "$browser:/tmp/financial-browser-original.json" "$temporary/browser-original.json"
docker cp "$browser:/tmp/financial-browser-session.json" "$temporary/browser-session.json"
docker cp "$browser:/tmp/financial-deposit-original.json" "$temporary/deposit-original.json"
docker cp "$browser:/tmp/financial-deposit-session.json" "$temporary/deposit-session.json"
docker exec "$application" php tests/fixtures/financial_http_verify.php reconfigure
docker restart "$application" >/dev/null
wait_application
docker create --name "$restart_browser" --network "$name" --init --shm-size=1g \
    -v "$root/tests/browser/financial-idempotency.config.cjs:/tests/financial-idempotency.config.cjs:ro" \
    -v "$root/tests/browser/financial-idempotency.spec.cjs:/tests/financial-idempotency.spec.cjs:ro" \
    -v "$root/tests/browser/capture-callbacks.cjs:/tests/capture-callbacks.cjs:ro" \
    "$H_SCRIPT_FINANCIAL_BROWSER_IMAGE" npx --no-install playwright test --config=financial-idempotency.config.cjs --grep restart >/dev/null
restart_browser_created=1
browser_restore_fixture "$restart_browser" "$temporary/original.json" /tmp/financial-original.json
browser_restore_fixture "$restart_browser" "$temporary/webmoney-original.json" /tmp/financial-webmoney-original.json
browser_restore_fixture "$restart_browser" "$temporary/browser-original.json" /tmp/financial-browser-original.json
browser_restore_fixture "$restart_browser" "$temporary/browser-session.json" /tmp/financial-browser-session.json
browser_restore_fixture "$restart_browser" "$temporary/deposit-original.json" /tmp/financial-deposit-original.json
browser_restore_fixture "$restart_browser" "$temporary/deposit-session.json" /tmp/financial-deposit-session.json
docker start -a "$restart_browser" || :
test "$(docker inspect "$restart_browser" --format '{{.State.ExitCode}}')" = 0
docker exec "$application" php tests/fixtures/financial_http_verify.php
gateway_capture_phase restart "$restart_browser"
echo 'Isolated financial HTTP suite passed, including application restart.'
