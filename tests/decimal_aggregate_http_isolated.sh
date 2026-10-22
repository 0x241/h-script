#!/bin/sh
# Run from the Compose root; installed runtime plus readonly tests, disposable DB only.
set -eu
set +x
umask 077
: "${1:?application image required}" "${2:?database image required}" "${H_SCRIPT_FINANCIAL_BROWSER_IMAGE:?browser image required}"
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
temporary=$(mktemp -d)
name="hscript-decimal-aggregate-$(basename "$temporary" | tr '[:upper:].' '[:lower:]-')"
database="$name-db" application="$name-app" browser="$name-browser" restart_browser="$name-restart"
network_created=0 database_created=0 application_created=0 browser_created=0 restart_created=0
cleanup() {
    if [ "$restart_created" = 1 ]; then docker rm -fv "$restart_browser" >/dev/null; fi
    if [ "$browser_created" = 1 ]; then docker rm -fv "$browser" >/dev/null; fi
    if [ "$application_created" = 1 ]; then docker rm -fv "$application" >/dev/null; fi
    if [ "$database_created" = 1 ]; then docker rm -fv "$database" >/dev/null; fi
    if [ "$network_created" = 1 ]; then docker network rm "$name" >/dev/null; fi
    rm -rf -- "$temporary"
}
trap cleanup EXIT HUP INT TERM
docker network create --internal "$name" >/dev/null; network_created=1
docker create --name "$database" --network "$name" --network-alias database --tmpfs /var/lib/mysql:rw,size=512m \
    --env MYSQL_ROOT_PASSWORD=synthetic-forecast-root --env MYSQL_DATABASE=fixture \
    --env MYSQL_USER=fixture --env MYSQL_PASSWORD=synthetic-forecast-password "$2" >/dev/null
database_created=1; docker start "$database" >/dev/null
docker create --name "$application" --network "$name" --network-alias fixture.invalid --entrypoint /bin/sh -w /var/www/html \
    --env H_SCRIPT_FINANCIAL_TEST=1 --env APP_DOMAIN=fixture.invalid --env APP_ENV=development --env REDIS_ENABLED=0 \
    --env DB_HOST=database:3306 --env DB_NAME=fixture --env DB_USER=fixture --env DB_PASSWORD=synthetic-forecast-password \
    --env APP_DATA_KEY=synthetic-forecast-key --env CONFIGURATOR_PASSWORD=synthetic-forecast-configurator \
    --env INSTALL_ADMIN_PASSWORD=synthetic-forecast-admin --env INSTALL_ADMIN_SECRET_ANSWER=fixture --env INSTALL_ADMIN_PIN=123456 \
    --env TELEMETRY_ENDPOINT=http://127.0.0.1:9/api/v1/installations --env RECOVERY_ENABLED=0 \
    --env CRON_TOKEN=synthetic-forecast-cron-token-00000001 \
    -v "$root/tests:/var/www/html/tests:ro" "$1" -eu -c '
        if [ ! -f /tmp/decimal-aggregate-installed ]; then
            attempt=0
            until php -r '\''require "vendor/autoload.php"; $db=new HScript\Database\Connection(); exit($db->open(getenv("DB_HOST"),getenv("DB_NAME"),getenv("DB_USER"),getenv("DB_PASSWORD"))?0:1);'\''; do
                attempt=$((attempt + 1)); test "$attempt" -lt 120; sleep 1
            done
            php /usr/local/share/hscript/write-config.php
            APP_AUTO_INSTALL=1 php /usr/local/share/hscript/install-db.php
            php tests/fixtures/financial_http_setup.php
            php tests/fixtures/decimal_aggregate_http_setup.php
            touch /tmp/decimal-aggregate-installed
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
docker start "$application" >/dev/null;wait_application
docker create --name "$browser" --network "$name" --init --shm-size=1g \
    -v "$root/tests/browser/decimal-aggregate.config.cjs:/tests/decimal-aggregate.config.cjs:ro" \
    -v "$root/tests/browser/decimal-aggregate.spec.cjs:/tests/decimal-aggregate.spec.cjs:ro" \
    -v "$root/tests/fixtures/decimal_aggregate_cases.json:/tests/fixtures/decimal_aggregate_cases.json:ro" \
    "$H_SCRIPT_FINANCIAL_BROWSER_IMAGE" npx --no-install playwright test --config=decimal-aggregate.config.cjs --grep-invert restart >/dev/null
browser_created=1;docker start -a "$browser" || :
test "$(docker inspect "$browser" --format '{{.State.ExitCode}}')" = 0
docker exec "$application" php tests/fixtures/decimal_aggregate_http_verify.php
docker restart "$application" >/dev/null;wait_application
docker create --name "$restart_browser" --network "$name" --init --shm-size=1g \
    -v "$root/tests/browser/decimal-aggregate.config.cjs:/tests/decimal-aggregate.config.cjs:ro" \
    -v "$root/tests/browser/decimal-aggregate.spec.cjs:/tests/decimal-aggregate.spec.cjs:ro" \
    -v "$root/tests/fixtures/decimal_aggregate_cases.json:/tests/fixtures/decimal_aggregate_cases.json:ro" \
    "$H_SCRIPT_FINANCIAL_BROWSER_IMAGE" npx --no-install playwright test --config=decimal-aggregate.config.cjs --grep restart >/dev/null
restart_created=1;docker start -a "$restart_browser" || :
test "$(docker inspect "$restart_browser" --format '{{.State.ExitCode}}')" = 0
docker exec "$application" php tests/fixtures/decimal_aggregate_http_verify.php replay
echo 'Decimal aggregate actual HTTP and restart read-only projections passed.'
