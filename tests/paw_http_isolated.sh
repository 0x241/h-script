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
name="hscript-paw-http-$(basename "$temporary" | tr '[:upper:].' '[:lower:]-')"
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
cat > "$temporary/paw-pool.conf" <<'EOF'
[www]
php_admin_value[auto_prepend_file] = /fixture/paw_http_prepend.php
EOF
cat > "$temporary/paw-http.config.cjs" <<'EOF'
const {defineConfig}=require('@playwright/test');
module.exports=defineConfig({testDir:'.',testMatch:'paw-http.spec.cjs',outputDir:'/tmp/paw-http-results',reporter:'line',workers:1,retries:0,timeout:60000,use:{baseURL:'http://fixture.invalid',trace:'off',screenshot:'off',video:'off'},projects:[{name:process.env.H_SCRIPT_PAW_BROWSER_NAME||'chromium',use:{browserName:process.env.H_SCRIPT_PAW_BROWSER_NAME||'chromium'}}]});
EOF
docker network create --internal "$name" >/dev/null
network_created=1
docker create --name "$database" --network "$name" --network-alias database --tmpfs /var/lib/mysql:rw,size=512m \
    --env MYSQL_ROOT_PASSWORD=synthetic-login-root --env MYSQL_DATABASE=fixture \
    --env MYSQL_USER=fixture --env MYSQL_PASSWORD=synthetic-login-password "$2" --log-bin-trust-function-creators=1 >/dev/null
database_created=1
docker start "$database" >/dev/null
docker create --platform "$application_platform" --name "$application" --network "$name" --network-alias fixture.invalid --entrypoint /bin/sh -w /var/www/html \
    --env H_SCRIPT_GATEWAY_HTTP_CAPTURE=1 --env H_SCRIPT_FINANCIAL_TEST=1 --env H_SCRIPT_PAW_HTTP_FIXTURE=1 \
    --env APP_DOMAIN=fixture.invalid --env APP_ENV=development --env REDIS_ENABLED=0 \
    --env DB_HOST=database:3306 --env DB_NAME=fixture --env DB_USER=fixture --env DB_PASSWORD=synthetic-login-password \
    --env APP_DATA_KEY=synthetic-login-key --env CONFIGURATOR_PASSWORD=synthetic-login-configurator \
    --env INSTALL_ADMIN_PASSWORD=synthetic-login-admin --env INSTALL_ADMIN_SECRET_ANSWER=fixture --env INSTALL_ADMIN_PIN=123456 \
    --env TELEMETRY_ENDPOINT=http://127.0.0.1:9/api/v1/installations --env RECOVERY_ENABLED=0 \
    --env CRON_TOKEN=synthetic-isolated-cron-token-00000001 \
    -v "$root/tests/fixtures/gateway_http_audit_prepend.php:/fixture/gateway_http_audit_prepend.php:ro" \
    -v "$root/tests:/var/www/html/tests:ro" \
    -v "$root/tests/fixtures/paw_http_prepend.php:/fixture/paw_http_prepend.php:ro" \
    -v "$temporary/paw-pool.conf:/usr/local/etc/php-fpm.d/zz-paw-fixture.conf:ro" \
    "$1" -eu -c '
        if [ ! -f /tmp/paw-http-installed ]; then
            attempt=0
            until php -r '\''require "vendor/autoload.php"; $db=new HScript\Database\Connection(); exit($db->open(getenv("DB_HOST"),getenv("DB_NAME"),getenv("DB_USER"),getenv("DB_PASSWORD"))?0:1);'\''; do
                attempt=$((attempt + 1)); test "$attempt" -lt 120; sleep 1
            done
            php /usr/local/share/hscript/write-config.php
            APP_AUTO_INSTALL=1 php /usr/local/share/hscript/install-db.php
            php tests/fixtures/financial_http_setup.php
            php /fixture/paw_http_prepend.php --setup
            php tests/test_access.php
            guard_status=0
            H_SCRIPT_PAW_HTTP_FIXTURE=0 php /fixture/paw_http_prepend.php --setup || guard_status=$?
            test "$guard_status" = 64
            guard_status=0
            APP_DOMAIN=example.com php /fixture/paw_http_prepend.php --setup || guard_status=$?
            test "$guard_status" = 64
            guard_status=0
            APP_ENV=production php /fixture/paw_http_prepend.php --setup || guard_status=$?
            test "$guard_status" = 64
            guard_status=0
            php tests/fixtures/paw_http_prepend.php --setup || guard_status=$?
            test "$guard_status" = 64
            echo "PAW fixture default/public-mount/environment guards passed (4 checks)."
            touch /tmp/paw-http-installed
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
    docker create --name "$browser" --network "$name" --init --shm-size=1g --env "H_SCRIPT_PAW_BROWSER_NAME=${H_SCRIPT_PAW_BROWSER_NAME:-chromium}" \
        -v "$root/tests/browser/paw-http.spec.cjs:/tests/paw-http.spec.cjs:ro" \
    -v "$root/tests/browser/capture-callbacks.cjs:/tests/capture-callbacks.cjs:ro" \
        "$H_SCRIPT_FINANCIAL_BROWSER_IMAGE" npx --no-install playwright test --config=paw-http.config.cjs --grep "$phase" >/dev/null
    browser_created=1
    browser_restore_fixture "$browser" "$temporary/paw-http.config.cjs" /tests/paw-http.config.cjs
    if [ -n "${paw_original_file:-}" ]; then
        browser_restore_fixture "$browser" "$paw_original_file" /tmp/paw-http-public.json
    fi
    if [ -n "${paw_native_file:-}" ]; then
        browser_restore_fixture "$browser" "$paw_native_file" /tmp/paw-native-http-public.json
    fi
    docker start -a "$browser" || :
    if [ "$(docker inspect "$browser" --format '{{.State.ExitCode}}')" != 0 ]; then
        docker cp "$browser:/tmp/paw-http-results" "$capture_directory/failure" >/dev/null 2>&1 || :
        docker logs --tail 60 "$application" > "$capture_directory/application-failure.log" 2>&1
        exit 1
    fi
}
docker start "$application" >/dev/null
wait_application
gateway_capture_begin "$1"
docker logs --tail 12 "$application"
run_browser '(^| )creation:'
docker exec "$application" php /fixture/paw_http_prepend.php --verify created
gateway_capture_phase created "$browser"
docker exec "$application" php /fixture/paw_http_prepend.php --history
docker cp "$browser:/tmp/paw-http-public.json" "$temporary/public.json"
docker rm -fv "$browser" >/dev/null; browser_created=0
paw_original_file="$temporary/public.json"
run_browser '(^| )management:'
if [ -n "${H_SCRIPT_PAW_UI_ARTIFACTS:-}" ]; then docker cp "$browser:/tmp/paw-currency-settings.png" "$H_SCRIPT_PAW_UI_ARTIFACTS/paw-currency-settings.png"; fi
docker exec "$application" php /fixture/paw_http_prepend.php --history-close
docker rm -fv "$browser" >/dev/null; browser_created=0
run_browser '(^| )retirement:'
docker exec "$application" php /fixture/paw_http_prepend.php --verify managed
gateway_capture_phase managed "$browser"
docker rm -fv "$browser" >/dev/null; browser_created=0
docker restart "$application" >/dev/null
wait_application
run_browser '(^| )restart:'
docker exec "$application" php /fixture/paw_http_prepend.php --verify restart
gateway_capture_phase restart "$browser"
docker rm -fv "$browser" >/dev/null; browser_created=0
run_browser '(^| )native:'
docker exec "$application" php /fixture/paw_http_prepend.php --native-verify
gateway_capture_phase native "$browser"
docker cp "$browser:/tmp/paw-native-http-public.json" "$temporary/native-public.json"
paw_native_file="$temporary/native-public.json"
docker rm -fv "$browser" >/dev/null; browser_created=0
docker restart "$application" >/dev/null
wait_application
run_browser '(^| )native-restart:'
docker exec "$application" php /fixture/paw_http_prepend.php --native-verify
gateway_capture_phase native-restart "$browser"
docker rm -fv "$browser" >/dev/null; browser_created=0
docker exec "$application" php /fixture/paw_http_prepend.php --wallet-setup
run_browser '(^| )wallets-rates:'
docker exec "$application" php /fixture/paw_http_prepend.php --wallet-verify
gateway_capture_phase wallets-rates "$browser"
docker rm -fv "$browser" >/dev/null; browser_created=0
docker exec "$application" php /fixture/paw_http_prepend.php --wallet-fault
run_browser '(^| )wallet-fault:'
docker exec "$application" php /fixture/paw_http_prepend.php --wallet-fault-verify
gateway_capture_phase wallet-fault "$browser"
docker rm -fv "$browser" >/dev/null; browser_created=0
run_browser '(^| )content:'
gateway_capture_phase content "$browser"
docker rm -fv "$browser" >/dev/null; browser_created=0
docker exec "$application" php /fixture/paw_http_prepend.php --schema-down
run_browser '(^| )schema:'
docker exec "$application" php /fixture/paw_http_prepend.php --verify schema
gateway_capture_phase schema "$browser"
echo 'Installed-candidate PAW common setup isolated HTTP suite passed, including restart and old-schema guard.'
