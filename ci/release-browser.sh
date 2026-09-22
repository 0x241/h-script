#!/bin/sh
set -eu
set +x
umask 077
: "${CANDIDATE_REF:?}" "${CANDIDATE_DIGEST:?}" "${CI_COMMIT_SHA:?}" "${CI_JOB_ID:?}"
printf '%s\n' "$CANDIDATE_DIGEST" | grep -Eq '^sha256:[a-f0-9]{64}$'
printf '%s\n' "$CI_JOB_ID" | grep -Eq '^[0-9]+$'
temporary=$(mktemp -d)
browser_image="hscript-release-browser:$CI_JOB_ID"
built=0
cleanup() {
    if [ "$built" = 1 ]; then docker image rm "$browser_image" >/dev/null 2>&1 || true; fi
    rm -rf -- "$temporary"
}
trap cleanup EXIT HUP INT TERM
export DOCKER_CONFIG="$temporary"
printf '%s' "$CI_REGISTRY_PASSWORD" | docker login "$CI_REGISTRY" --username "$CI_REGISTRY_USER" --password-stdin
exact="${CANDIDATE_REF%@*}@$CANDIDATE_DIGEST"
test "$(docker buildx imagetools inspect "$exact" --format '{{.Manifest.Digest}}')" = "$CANDIDATE_DIGEST"
mkdir -p dist
{
    printf 'source_sha=%s\nimage=%s\n' "$CI_COMMIT_SHA" "$exact"
    docker build -t "$browser_image" tests/browser
    built=1
    # The separate browser lockfile is dev tooling; high findings/network failure block.
    docker run --rm "$browser_image" npm audit --include=dev --audit-level=high
    H_SCRIPT_BROWSER_IMAGE="$browser_image" sh tests/update_isolated.sh "$exact" \
        mysql:8.4@sha256:85b9bf2e29cf836ecb8c2a15a935d4ba0c606631dff1dd79531a11983c638f2a
    echo 'result=passed'
} > dist/release-browser.txt 2>&1
cat dist/release-browser.txt
