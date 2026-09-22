#!/bin/sh
# Called only after signature verification and the release evidence gate.
set -eu
set +x
mode=$1
source=$2
digest=$3
version=$4
shift 4
case "$mode" in check|promote) ;; *) exit 1;; esac
printf '%s\n' "$version" | grep -Eq '^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$'
printf '%s\n' "$digest" | grep -Eq '^sha256:[a-f0-9]{64}$'
test "$#" -gt 0
guard_errors=$(mktemp)
trap 'rm -f -- "$guard_errors"' EXIT HUP INT TERM
inspect_tag() {
    inspected=''
    if inspected=$(docker buildx imagetools inspect "$1" --format '{{.Manifest.Digest}}' 2>"$guard_errors"); then
        test "$inspected" = "$digest" || { echo 'Immutable release digest conflict' >&2; return 1; }
    else
        # Authorization, network, rate-limit and server failures are NOT absence.
        grep -Eq 'manifest unknown|: not found$' "$guard_errors" || { echo 'Registry lookup failed closed' >&2; return 1; }
        inspected=''
    fi
}
test "$(docker buildx imagetools inspect "${source%@*}@$digest" --format '{{.Manifest.Digest}}')" = "$digest"
# Preflight every registry before the first write; recheck before each full tag write.
for repository in "$@"; do inspect_tag "$repository:$version"; done
test "$mode" = promote || exit 0
for repository in "$@"; do
    inspect_tag "$repository:$version"
    if [ -z "$inspected" ]; then
        docker buildx imagetools create --tag "$repository:$version" "${source%@*}@$digest"
    fi
    test "$(docker buildx imagetools inspect "$repository:$version" --format '{{.Manifest.Digest}}')" = "$digest"
    printf '%s@%s\n' "$repository:$version" "$digest"
    for alias in "${version%.*}" "${version%%.*}"; do
        docker buildx imagetools create --tag "$repository:$alias" "${source%@*}@$digest"
        test "$(docker buildx imagetools inspect "$repository:$alias" --format '{{.Manifest.Digest}}')" = "$digest"
    done
done
