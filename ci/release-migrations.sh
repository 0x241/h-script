#!/bin/sh
# Run only against disposable databases on a private network, never staging data.
set -eu
set +x
umask 077
: "${CANDIDATE_REF:?}" "${CANDIDATE_DIGEST:?}" "${CI_COMMIT_SHA:?}"
printf '%s\n' "$CANDIDATE_DIGEST" | grep -Eq '^sha256:[a-f0-9]{64}$'
temporary=$(mktemp -d)
cleanup() {
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
    for engine in mysql84 mariadb1011 mariadb114; do
        case "$engine" in
            mysql84) image='mysql:8.4@sha256:85b9bf2e29cf836ecb8c2a15a935d4ba0c606631dff1dd79531a11983c638f2a' ;;
            mariadb1011) image='mariadb:10.11@sha256:07c0aaff7396b74cb7975cba78257178d188e30f531a5db2b617c48beef13c41' ;;
            mariadb114) image='mariadb:11.4@sha256:80494b9810694179889f7281ec44ca928241df577159c0356a1070e2e94616a1' ;;
        esac
        printf 'database=%s\n' "$image"
        sh tests/update_isolated.sh "$exact" "$image"
        sh tests/telemetry_isolated.sh "$exact" "$image"
    done
    echo 'result=passed'
} > dist/release-migrations.txt 2>&1
cat dist/release-migrations.txt
