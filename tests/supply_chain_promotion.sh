#!/bin/sh
set -eu
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT HUP INT TERM
mkdir "$test_root/bin"
cp tests/fixtures/mock-registry.sh "$test_root/bin/docker"
chmod 0700 "$test_root/bin/docker"
export PATH="$test_root/bin:$PATH"
export MOCK_DIGEST='sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
for scenario in same absent conflict unauthorized network later-conflict race; do
    export MOCK_MODE="$scenario" MOCK_ROOT="$test_root/$scenario"
    mkdir "$MOCK_ROOT"
    if sh ci/supply-chain/promote.sh promote source "$MOCK_DIGEST" 1.2.3 first second > "$MOCK_ROOT/output" 2>&1; then
        case "$scenario" in same|absent) ;; *) echo 'Unsafe promotion was accepted' >&2; exit 1;; esac
        if [ "$scenario" = same ]; then ! grep -q -- '--tag .*:1.2.3 ' "$MOCK_ROOT/writes"; fi
        if [ "$scenario" = absent ]; then grep -q -- '--tag first:1.2.3 ' "$MOCK_ROOT/writes"; fi
    else
        case "$scenario" in same|absent) echo 'Safe promotion was rejected' >&2; exit 1;; esac
        test ! -f "$MOCK_ROOT/writes"
    fi
done
echo 'Immutable tag, idempotency, all-registry preflight and lookup failure tests passed.'
