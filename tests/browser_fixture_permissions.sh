#!/bin/sh
# Native container permissions reproduce the Linux runner failure on Desktop too.
set -eu
set +x
umask 077
: "${1:?cached non-root browser image required}"
H_SCRIPT_FINANCIAL_BROWSER_IMAGE=$1
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
. "$root/tests/browser_fixtures.sh"
temporary=$(mktemp -d)
browser="hscript-browser-permissions-$(basename "$temporary" | tr '[:upper:].' '[:lower:]-')"
browser_created=0
cleanup() {
    if [ "$browser_created" = 1 ]; then docker rm -fv "$browser" >/dev/null; fi
    rm -rf -- "$temporary"
}
trap cleanup EXIT HUP INT TERM
printf '%s\n' 'synthetic private browser state' > "$temporary/state.txt"
printf '%s\n' 'module.exports = { fixture: "synthetic" };' > "$temporary/config.cjs"
docker create --name "$browser" --network none --entrypoint node "$1" -e '
    const fs = require("node:fs"), assert = require("node:assert/strict");
    assert.notEqual(process.getuid(), 0);
    assert.throws(() => fs.readFileSync("/tmp/root-owned.txt"), {code:"EACCES"});
    for (const path of ["/tmp/browser-state.txt", "/tests/browser-fixture.config.cjs"]) {
        const stat = fs.statSync(path);
        assert.equal(stat.uid, process.getuid());
        assert.equal(stat.gid, process.getgid());
        assert.equal(stat.mode & 0o777, 0o400);
        assert.throws(() => fs.writeFileSync(path, "changed"), {code:"EACCES"});
    }
    assert.equal(fs.readFileSync("/tmp/browser-state.txt", "utf8"), "synthetic private browser state\n");
    assert.deepEqual(require("/tests/browser-fixture.config.cjs"), {fixture:"synthetic"});
    console.log("Browser fixture ownership/read-only regression passed, including root-owned EACCES control.");
' >/dev/null
browser_created=1
docker cp "$temporary/state.txt" "$browser:/tmp/root-owned.txt"
browser_restore_fixture "$browser" "$temporary/state.txt" /tmp/browser-state.txt
browser_restore_fixture "$browser" "$temporary/config.cjs" /tests/browser-fixture.config.cjs
docker start -a "$browser"
test "$(docker inspect "$browser" --format '{{.State.ExitCode}}')" = 0
