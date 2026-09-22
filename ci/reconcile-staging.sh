#!/bin/sh
# Execute under the staging-runtime resource group. No bootstrap/repair/migration.
set -eu
set +x
umask 077
sh ci/verify-staging-image.sh
container=$(docker ps -q \
    --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME" \
    --filter label=com.docker.compose.service=app \
    --filter label=com.docker.compose.oneoff=False)
set -- $container
test "$#" -eq 1
mkdir -p dist
if ! docker exec "$1" php bin/update.php reconcile > dist/staging-reconciliation.json; then
    # The CLI output contains only bounded status/check IDs, never raw exceptions.
    cat dist/staging-reconciliation.json
    exit 1
fi
sh ci/verify-staging-image.sh
echo 'Staging read-only reconciliation passed.'
