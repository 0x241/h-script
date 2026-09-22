#!/bin/sh
# Requires only Docker Compose; PHP runs in the existing pinned CI image.
set -eu
set +x
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
. "$root/ci/supply-chain/common.sh"
check_json() {
    docker run --rm -i --network none --entrypoint php \
        -v "$root/tests/recovery_compose.php:/test.php:ro" \
        "$SC_COMPOSER" /test.php "$@"
}
check_policy() {
    docker compose --project-directory "$root" --env-file "$root/docker/env.example" -f "$root/docker-compose.yml" config --format json | check_json "$@"
}
check_json --self-test </dev/null
check_policy
RECOVERY_CMS_RPO_SECONDS=1234 RECOVERY_DRILL_MAX_AGE_SECONDS=4567 check_policy --overrides
echo 'Shared recovery policy and web/drill secret isolation passed.'
