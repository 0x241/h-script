#!/bin/sh
# Owns a unique disposable database/network, never uses an operator database.
set -eu
: "${1:?application image required}" "${2:?database image required}"
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
temporary=$(mktemp -d)
name="hscript-proof-$(basename "$temporary" | tr '[:upper:].' '[:lower:]-')"
database="$name-db"
application="$name-app"
network_created=0
database_created=0
application_created=0
cleanup() {
    if [ "$application_created" = 1 ]; then docker rm -fv "$application" >/dev/null; fi
    if [ "$database_created" = 1 ]; then docker rm -fv "$database" >/dev/null; fi
    if [ "$network_created" = 1 ]; then docker network rm "$name" >/dev/null; fi
    rmdir "$temporary"
}
trap cleanup EXIT HUP INT TERM
docker network create --internal "$name" >/dev/null
network_created=1
docker create --name "$database" --network "$name" --network-alias database --tmpfs /var/lib/mysql:rw,size=1g \
    --env MYSQL_ROOT_PASSWORD=synthetic-proof-root --env MYSQL_DATABASE=fixture \
    --env MYSQL_USER=fixture --env MYSQL_PASSWORD=synthetic-proof-password "$2" >/dev/null
database_created=1
docker start "$database" >/dev/null
docker create --name "$application" --network "$name" --entrypoint /bin/sh \
    --env APP_ENV=development --env APP_DOMAIN=fixture.invalid --env REDIS_ENABLED=0 \
    --env DB_HOST=database:3306 --env DB_NAME=fixture --env DB_USER=fixture --env DB_PASSWORD=synthetic-proof-password \
    --env H_SCRIPT_TELEMETRY_DB_TEST=1 --env H_SCRIPT_TELEMETRY_DB_NAME=fixture --env H_SCRIPT_TELEMETRY_EMPTY_BASE=1 \
    -v "$root/tests:/var/www/html/tests:ro" "$1" -eu -c '
        php /usr/local/share/hscript/write-config.php
        attempt=0
        until php -r '\''require "vendor/autoload.php"; $db=new HScript\Database\Connection(); exit($db->open(getenv("DB_HOST"), getenv("DB_NAME"), getenv("DB_USER"), getenv("DB_PASSWORD")) ? 0 : 1);'\''; do
            attempt=$((attempt + 1))
            test "$attempt" -lt 120
            sleep 1
        done
        for test in domain_proof telemetry backup recovery telemetry_database_integration; do
            TEST_FILE="tests/$test.php" php -r '\''try { require getenv("TEST_FILE"); } catch (Throwable $e) { fwrite(STDERR, (string)$e . "\n"); exit(1); }'\''
        done
    ' >/dev/null
application_created=1
docker start -a "$application"
test "$(docker inspect "$application" --format '{{.State.ExitCode}}')" = 0
