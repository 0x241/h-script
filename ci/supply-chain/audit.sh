#!/bin/sh
set -eu
. ci/supply-chain/common.sh
sc_init
for file in composer.json composer.lock package.json package-lock.json; do
    if [ -n "${2:-}" ]; then
        printf '%s\n' "$2" | grep -Eq '^[a-f0-9]{40}$'
        git show "$2:$file" > "$SC_WORK/$file"
    else cp "$file" "$SC_WORK/"; fi
done
status=0
case "${1:-}" in
composer)
    docker run --rm --user "$(id -u):$(id -g)" --env COMPOSER_HOME=/tmp/composer \
        -v "$SC_WORK:/work" -w /work --entrypoint composer "$SC_COMPOSER" \
        --no-plugins --no-scripts audit --locked --no-dev --abandoned=report --format=json > "$SC_WORK/raw.json" 2> "$SC_WORK/error" || status=$?
    sc_php /src/ci/supply-chain/gate.php composer production /work/raw.json "/reports/$SC_REPORT_SUBDIR/composer.report.json" "$status"
    docker run --rm --entrypoint composer "$SC_COMPOSER" --version --no-ansi > "dist/$SC_REPORT_SUBDIR/composer-version.txt"
    ;;
npm)
    for scope in production toolchain; do
        status=0
        if [ "$scope" = production ]; then omit=--omit=dev; else omit=--include=dev; fi
        docker run --rm --user "$(id -u):$(id -g)" --env npm_config_cache=/tmp/npm \
            -v "$SC_WORK:/work" -w /work "$SC_NODE" npm audit --package-lock-only --ignore-scripts \
            --audit-level=high "$omit" --json > "$SC_WORK/raw.json" 2> "$SC_WORK/error" || status=$?
        sc_php /src/ci/supply-chain/gate.php npm "$scope" /work/raw.json "/reports/$SC_REPORT_SUBDIR/npm-$scope.report.json" "$status"
    done
    docker run --rm "$SC_NODE" sh -c 'node --version; npm --version' > "dist/$SC_REPORT_SUBDIR/node-npm-version.txt"
    ;;
*) exit 1;;
esac
