#!/bin/sh
set -eu
image=${1:-hscript-pcre2-hotfix-test:latest}
platform=$(docker image inspect --format "{{.Os}}/{{.Architecture}}" "$image")
root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
docker run --rm --platform "$platform" --network none --entrypoint php --mount "type=bind,src=$root,dst=/var/www/html,readonly" --tmpfs /tmp:rw,noexec,nosuid,size=32m "$image" tests/ndjson_retention.php
docker run --rm --platform "$platform" --network none --entrypoint php --env H_SCRIPT_NDJSON_DISK_TEST=1 --mount "type=bind,src=$root,dst=/var/www/html,readonly" --tmpfs /full:rw,noexec,nosuid,size=64k "$image" tests/ndjson_disk_full.php
docker run --rm --platform "$platform" --network none --entrypoint php --mount "type=bind,src=$root,dst=/var/www/html,readonly" "$image" tests/csp_reports.php
docker run --rm --platform "$platform" --network none --entrypoint php --mount "type=bind,src=$root,dst=/var/www/html,readonly" "$image" tests/observability.php
docker run --rm --platform "$platform" --network none --entrypoint php --mount "type=bind,src=$root,dst=/var/www/html,readonly" "$image" tests/observability_regressions.php
docker run --rm --platform "$platform" --network none --entrypoint php --mount "type=bind,src=$root,dst=/var/www/html,readonly" "$image" tests/observability_fpm_integration.php
docker run --rm --platform "$platform" --network none --entrypoint /bin/sh \
    --env H_SCRIPT_NDJSON_RUNTIME_TEST=1 \
    --mount "type=bind,src=$root/src,dst=/var/www/html/src,readonly" \
    --mount "type=bind,src=$root/lib,dst=/var/www/html/lib,readonly" \
    --mount "type=bind,src=$root/vendor,dst=/var/www/html/vendor,readonly" \
    --mount "type=bind,src=$root/tests,dst=/var/www/html/tests,readonly" "$image" -eu -c '
        mkdir -p /var/www/shared/logs
        if [ ! -L /var/www/html/logs ]; then ln -s /var/www/shared/logs /var/www/html/logs; fi
        chown -h www-data:www-data /var/www/html/logs
        chown www-data:www-data /var/www/shared/logs
        chmod 0700 /var/www/shared/logs
        su -s /bin/sh www-data -c "php tests/ndjson_runtime_alias.php"
    '
