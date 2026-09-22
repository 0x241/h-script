#!/bin/sh
# Read-only check on the same Docker host used by deploy:staging.
set -eu
: "${COMPOSE_PROJECT_NAME:?}" "${CANDIDATE_REF:?}" "${CANDIDATE_DIGEST:?}"
printf '%s\n' "$CANDIDATE_DIGEST" | grep -Eq '^sha256:[a-f0-9]{64}$'
exact="${CANDIDATE_REF%@*}@$CANDIDATE_DIGEST"
expected_id=$(docker image inspect "$exact" --format '{{.Id}}')
for service in app cron; do
    containers=$(docker ps -aq \
        --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME" \
        --filter "label=com.docker.compose.service=$service" \
        --filter label=com.docker.compose.oneoff=False)
    set -- $containers
    test "$#" -eq 1
    test "$(docker inspect "$1" --format '{{.State.Running}}')" = true
    if [ "$(docker inspect "$1" --format '{{.Config.Image}}')" != "$exact" ] \
        || [ "$(docker inspect "$1" --format '{{.Image}}')" != "$expected_id" ]; then
        echo 'Staging image differs from this pipeline candidate. Run verification in the pipeline of the currently deployed image; do not retry an older pipeline.' >&2
        exit 1
    fi
done
printf 'Verified staging app/cron at %s\n' "$exact"
