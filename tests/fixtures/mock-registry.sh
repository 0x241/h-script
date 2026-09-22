#!/bin/sh
set -eu
test "$1 $2" = 'buildx imagetools'
operation=$3
shift 3
if [ "$operation" = inspect ]; then
    case "$1" in *@sha256:*) printf '%s\n' "$MOCK_DIGEST"; exit 0;; esac
    if [ -f "$MOCK_ROOT/written" ]; then printf '%s\n' "$MOCK_DIGEST"; exit 0; fi
    case "$MOCK_MODE" in
        same) printf '%s\n' "$MOCK_DIGEST";;
        conflict) printf 'sha256:%064d\n' 0;;
        absent) echo "ERROR: $1: not found" >&2; exit 1;;
        unauthorized) echo 'ERROR: unauthorized' >&2; exit 1;;
        network) echo 'ERROR: connection refused' >&2; exit 1;;
        later-conflict)
            case "$1" in second:*) printf 'sha256:%064d\n' 0;; *) echo "ERROR: $1: not found" >&2; exit 1;; esac;;
        race)
            if [ -f "$MOCK_ROOT/checked" ]; then printf 'sha256:%064d\n' 0;
            else touch "$MOCK_ROOT/checked"; echo "ERROR: $1: not found" >&2; exit 1; fi;;
        *) exit 1;;
    esac
elif [ "$operation" = create ]; then
    printf '%s\n' "$*" >> "$MOCK_ROOT/writes"
    touch "$MOCK_ROOT/written"
else exit 1; fi
