#!/bin/sh
# Run only against disposable databases on a private network, never staging data.
set -eu
set +x
umask 077
exec 3>&1
: "${CANDIDATE_REF:?}" "${CANDIDATE_DIGEST:?}" "${CI_COMMIT_SHA:?}"
printf '%s\n' "$CANDIDATE_DIGEST" | grep -Eq '^sha256:[a-f0-9]{64}$'
temporary=$(mktemp -d)
cleanup() {
    migration_result=$?
    if [ "$migration_result" -ne 0 ] && [ -f dist/release-migrations.txt ]; then
        tail -n 160 dist/release-migrations.txt >&3 || true
    fi
    rm -rf -- "$temporary"
    exit "$migration_result"
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
    python3 ci/run-test-suites.py --jobs "${RELEASE_TEST_PARALLELISM:-2}" \
        --log-dir dist/release-migrations --worker ci/release-migrations-engine.sh --image "$exact" --progress-fd 3 \
        mysql84 mariadb1011 mariadb114
    echo 'result=passed'
} > dist/release-migrations.txt 2>&1
cat dist/release-migrations.txt
