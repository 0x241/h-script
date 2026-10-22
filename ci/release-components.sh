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
        -v "$component_root/tests:/var/www/html/tests:ro" \
        -v "$component_root/ci/security:/var/www/html/ci/security:ro" "$exact" -eu -c '
            cd /var/www/html
            test "$(tr -d "\r\n" < VERSION)" = "$EXPECTED_APPLICATION_VERSION"
            test "$(tr -d "\r\n" < SCHEMA_VERSION)" = "$EXPECTED_SCHEMA_VERSION"
            for suite in decimal_money decimal_exchange decimal_deposit decimal_referral decimal_output decimal_forecast decimal_aggregate decimal_plan decimal_configuration decimal_closure gateway_decimal_wire fiat_rates crypto_rates rate_update update_foundation update_activation update_runtime api_v1 job_queue telemetry domain_proof telemetry_pagination_scale backup payment_gateways gateway_webhooks gateway_callback_policy paykassa_callbacks volet_callbacks ccapi_callbacks paw_provider stripe_provider token_routes token_catalog security_redaction recovery integrity configurator_lifecycle translations configurator_translations template_runtime_helpers json_ld_output csp_policy csp_reports ndjson_retention csp_turnstile htmx_csp session_csrf cron_authentication failed_login_policy test_access; do
                php "tests/$suite.php"
            done
            sh tests/recovery_scheduler.sh
            php ci/security/check-route-matrix.php
        '
    docker run --rm --network none --entrypoint php \
        --env H_SCRIPT_NDJSON_DISK_TEST=1 \
        --tmpfs /full:rw,noexec,nosuid,size=64k \
        -v "$component_root/tests:/var/www/html/tests:ro" "$exact" tests/ndjson_disk_full.php
    docker run --rm --network none --entrypoint php --user www-data \
        --env H_SCRIPT_NDJSON_RUNTIME_TEST=1 \
        -v "$component_root/tests:/var/www/html/tests:ro" "$exact" tests/ndjson_runtime_alias.php
    sh tests/ndjson_upgrade_isolated.sh "$exact"
    echo 'result=passed'
} > dist/release-components.txt 2>&1
cat dist/release-components.txt
