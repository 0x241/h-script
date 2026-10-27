#!/bin/sh
set -eu
set +x
umask 077
# An EXIT trap can run inside the redirected suite group; retain job stdout.
exec 3>&1
: "${CANDIDATE_REF:?}" "${CANDIDATE_DIGEST:?}" "${CI_COMMIT_SHA:?}" "${CI_JOB_ID:?}"
printf '%s\n' "$CANDIDATE_DIGEST" | grep -Eq '^sha256:[a-f0-9]{64}$'
printf '%s\n' "$CI_JOB_ID" | grep -Eq '^[0-9]+$'
temporary=$(mktemp -d)
browser_image="hscript-release-browser:$CI_JOB_ID"
built=0
cleanup() {
    browser_result=$?
    if [ "$browser_result" -ne 0 ] && [ -f dist/release-browser.txt ]; then
        tail -n 160 dist/release-browser.txt >&3 || true
    fi
    if [ "$built" = 1 ]; then docker image rm "$browser_image" >/dev/null 2>&1 || true; fi
    rm -rf -- "$temporary"
    exit "$browser_result"
}
trap cleanup EXIT HUP INT TERM
export DOCKER_CONFIG="$temporary"
export PATH="$(pwd)/ci:$PATH"
python3 ci/docker-credential-hscript-ci configure "$DOCKER_CONFIG"
exact="${CANDIDATE_REF%@*}@$CANDIDATE_DIGEST"
test "$(docker buildx imagetools inspect "$exact" --format '{{.Manifest.Digest}}')" = "$CANDIDATE_DIGEST"
mkdir -p dist
{
    printf 'source_sha=%s\nimage=%s\n' "$CI_COMMIT_SHA" "$exact"
    docker build -t "$browser_image" tests/browser
    built=1
    # The separate browser lockfile is dev tooling; high findings/network failure block.
    docker run --rm "$browser_image" npm audit --include=dev --audit-level=high
    sh tests/browser_fixture_permissions.sh "$browser_image"
    export RELEASE_BROWSER_IMAGE="$browser_image"
    python3 ci/run-test-suites.py --jobs "${RELEASE_TEST_PARALLELISM:-2}" \
        --log-dir dist/release-browser --worker ci/release-browser-suite.sh --image "$exact" --progress-fd 3 \
        update_isolated failed_login_isolated financial_http_isolated gateway_policy_http_isolated \
        inbound_http_isolated ccapi_http_isolated ccapi_payout_http_isolated paykassa_http_isolated paw_http_isolated volet_http_isolated
    echo 'result=passed'
} > dist/release-browser.txt 2>&1
cat dist/release-browser.txt
