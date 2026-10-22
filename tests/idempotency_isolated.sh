#!/bin/sh
# Isolated phase-2 foundation tests; never connect to a populated installation.
set -eu
set +x
: "${1:?application image required}" "${2:?database image required}"
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
temporary=$(mktemp -d)
name="hscript-idempotency-$(basename "$temporary" | tr '[:upper:].' '[:lower:]-')"
database="$name-db"
network_created=0 database_created=0
cleanup() {
    if [ "$database_created" = 1 ]; then docker rm -fv "$database" >/dev/null; fi
    if [ "$network_created" = 1 ]; then docker network rm "$name" >/dev/null; fi
    rm -rf -- "$temporary"
}
trap cleanup EXIT HUP INT TERM
docker network create --internal "$name" >/dev/null
network_created=1
docker create --name "$database" --network "$name" --network-alias database --tmpfs /var/lib/mysql:rw,size=512m \
    --env MYSQL_ROOT_PASSWORD=synthetic-idempotency-root --env MYSQL_DATABASE=fixture \
    --env MYSQL_USER=fixture --env MYSQL_PASSWORD=synthetic-idempotency-password "$2" >/dev/null
database_created=1
docker start "$database" >/dev/null
docker run --rm --network "$name" --entrypoint /bin/sh -w /var/www/html \
    --env APP_ENV=development --env APP_DOMAIN=fixture.invalid --env H_SCRIPT_FINANCIAL_TEST=1 --env DB_HOST=database:3306 --env DB_NAME=fixture \
    --env DB_USER=fixture --env DB_PASSWORD=synthetic-idempotency-password \
    -v "$root/tests:/var/www/html/tests:ro" "$1" -eu -c '
        attempt=0
        until php -d display_errors=1 -d error_log=/dev/stderr -r '\''require "vendor/autoload.php"; $db=new HScript\Database\Connection(); exit($db->open(getenv("DB_HOST"),getenv("DB_NAME"),getenv("DB_USER"),getenv("DB_PASSWORD"))?0:1);'\''; do
            attempt=$((attempt + 1)); test "$attempt" -lt 90; sleep 1
        done
        echo "Fixture database ready."
        php -d display_errors=1 -d error_log=/dev/stderr tests/idempotency_database.php
    '
echo 'Isolated idempotency foundation suite passed.'
