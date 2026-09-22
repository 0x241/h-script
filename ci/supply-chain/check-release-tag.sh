#!/bin/sh
set -eu
tag=$1
printf '%s\n' "$tag" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+$'
# FETCH_HEAD may name the first fetched tag, not the public branch.
# Fetch full ancestry even when the runner started with a shallow checkout.
set --
if [ "$(git rev-parse --is-shallow-repository)" = true ]; then set -- --unshallow; fi
git fetch "$@" origin "refs/tags/$tag:refs/tags/$tag" \
    '+refs/heads/release/public:refs/remotes/origin/release/public'
tag_sha=$(git rev-parse "$tag^{commit}")
git merge-base --is-ancestor "$tag_sha" refs/remotes/origin/release/public
printf '%s\n' "$tag_sha"
