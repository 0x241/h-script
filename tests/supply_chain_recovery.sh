#!/bin/sh
set -eu
review_root=$(mktemp -d)
trap 'rm -rf -- "$review_root"' EXIT HUP INT TERM
checker="$(pwd)/ci/supply-chain/check-release-tag.sh"
export GIT_AUTHOR_NAME=Review GIT_AUTHOR_EMAIL=review@example.invalid
export GIT_COMMITTER_NAME=Review GIT_COMMITTER_EMAIL=review@example.invalid
git init -q "$review_root/origin"
git -C "$review_root/origin" commit -q --allow-empty -m baseline
git -C "$review_root/origin" tag v1.2.2
git -C "$review_root/origin" commit -q --allow-empty -m public
git -C "$review_root/origin" branch release/public
git -C "$review_root/origin" commit -q --allow-empty -m unpublished
git -C "$review_root/origin" tag v1.2.3
git init -q "$review_root/client"
git -C "$review_root/client" remote add origin "$review_root/origin"
if (cd "$review_root/client" && sh "$checker" v1.2.3) > "$review_root/rejected.log" 2>&1; then
    echo 'Unpublished tag passed ancestry gate' >&2; exit 1
fi
(cd "$review_root/client" && sh "$checker" v1.2.2) > "$review_root/accepted.log" 2>&1
test "$(tail -n 1 "$review_root/accepted.log")" = "$(git -C "$review_root/origin" rev-parse v1.2.2)"
git clone -q --depth=1 --branch release/public "file://$review_root/origin" "$review_root/shallow"
(cd "$review_root/shallow" && sh "$checker" v1.2.2) > "$review_root/shallow.log" 2>&1
test "$(tail -n 1 "$review_root/shallow.log")" = "$(git -C "$review_root/origin" rev-parse v1.2.2)"
echo 'Recovery rejects unpublished tags; historical public tags and shallow checkouts pass.'
