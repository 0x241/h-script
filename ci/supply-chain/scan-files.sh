#!/bin/sh
set -eu
. ci/supply-chain/common.sh
sc_init
case "${1:-}" in
source)
    if [ -n "${CI_COMMIT_SHA:-}" ]; then
        git archive --format=tar "$CI_COMMIT_SHA" > "$SC_WORK/source.tar"
    else
        git ls-files --cached --others --exclude-standard -z > "$SC_WORK/source-files"
        COPYFILE_DISABLE=1 tar --null -T "$SC_WORK/source-files" -cf "$SC_WORK/source.tar"
    fi
    sc_php /src/ci/supply-chain/check-files.php source /work/source.tar
    mkdir "$SC_WORK/tree"
    tar -xf "$SC_WORK/source.tar" -C "$SC_WORK/tree"
    target=/work/tree
    ;;
shared)
    test -s "$2"
    cp "$2" "$SC_WORK/shared.tar.gz"
    sc_php /src/ci/supply-chain/check-files.php shared /work/shared.tar.gz
    tar -xzf "$SC_WORK/shared.tar.gz" -C "$SC_WORK"
    target=/work/h-script
    ;;
*) exit 1;;
esac
# No raw scanner result or stderr is uploaded: secret snippets remain ephemeral.
sc_trivy fs --scanners vuln,secret --format json --output /work/raw.json "$target" 2> "$SC_WORK/error"
sc_php /src/ci/supply-chain/gate.php trivy "$1" /work/raw.json "/reports/$SC_REPORT_SUBDIR/$1.report.json" 0
sc_trivy fs --scanners vuln --format cyclonedx --output "/reports/$SC_REPORT_SUBDIR/$1.cdx.json" "$target" 2> "$SC_WORK/error"
