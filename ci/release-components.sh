#!/bin/sh
# Component checks execute installed code from the candidate, not a source bind.
set -eu
set +x
umask 077
: "${CANDIDATE_REF:?}" "${CANDIDATE_DIGEST:?}" "${CI_COMMIT_SHA:?}"
printf '%s\n' "$CANDIDATE_DIGEST" | grep -Eq '^sha256:[a-f0-9]{64}$'
component_root=$(pwd)
component_credentials=$(mktemp -d)
trap 'rm -rf -- "$component_credentials"' EXIT HUP INT TERM
export DOCKER_CONFIG="$component_credentials"
printf '%s' "$CI_REGISTRY_PASSWORD" | docker login "$CI_REGISTRY" --username "$CI_REGISTRY_USER" --password-stdin
exact="${CANDIDATE_REF%@*}@$CANDIDATE_DIGEST"
test "$(docker buildx imagetools inspect "$exact" --format '{{.Manifest.Digest}}')" = "$CANDIDATE_DIGEST"
mkdir -p dist
{
    printf 'source_sha=%s\nimage=%s\n' "$CI_COMMIT_SHA" "$exact"
    docker run --rm --network none --entrypoint /bin/sh \
        --env "EXPECTED_APPLICATION_VERSION=$(tr -d '\r\n' < VERSION)" \
        --env "EXPECTED_SCHEMA_VERSION=$(tr -d '\r\n' < SCHEMA_VERSION)" \
        -v "$component_root/tests:/var/www/html/tests:ro" "$exact" -eu -c '
            cd /var/www/html
            test "$(tr -d "\r\n" < VERSION)" = "$EXPECTED_APPLICATION_VERSION"
            test "$(tr -d "\r\n" < SCHEMA_VERSION)" = "$EXPECTED_SCHEMA_VERSION"
            for suite in update_foundation update_activation update_runtime api_v1 job_queue telemetry domain_proof telemetry_pagination_scale backup payment_gateways security_redaction recovery integrity configurator_lifecycle translations configurator_translations; do
                php "tests/$suite.php"
            done
            sh tests/recovery_scheduler.sh
        '
    echo 'result=passed'
} > dist/release-components.txt 2>&1
cat dist/release-components.txt
