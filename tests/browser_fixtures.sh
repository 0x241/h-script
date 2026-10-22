#!/bin/sh
# Sourced only by disposable browser runners. Host UID mappings differ between
# native Linux and Docker Desktop; never bind private host files to another UID.
browser_fixture_identity=
browser_restore_fixture() {
    if [ -z "$browser_fixture_identity" ]; then
        browser_fixture_identity=$(docker run --rm --network none --entrypoint sh \
            "$H_SCRIPT_FINANCIAL_BROWSER_IMAGE" -c 'printf "%s:%s\n" "$(id -u)" "$(id -g)"')
    fi
    browser_fixture_directory=$(python3 - "$2" "$3" "$browser_fixture_identity" "$temporary/browser-fixture.tar" <<'PY'
import io
from pathlib import Path, PurePosixPath
import re
import sys
import tarfile

source, target, identity, archive = sys.argv[1:]
if not re.fullmatch(r'/(?:tmp|tests)/[A-Za-z0-9][A-Za-z0-9._-]*', target):
    raise SystemExit('Invalid browser fixture destination')
uid, gid = map(int, identity.split(':'))
if uid <= 0 or gid < 0:
    raise SystemExit('Browser fixture requires a non-root browser user')
path = Path(source)
if path.is_symlink() or not path.is_file():
    raise SystemExit('Browser fixture must be a regular file')
contents = path.read_bytes()
entry = tarfile.TarInfo(PurePosixPath(target).name)
entry.uid, entry.gid, entry.mode, entry.size = uid, gid, 0o400, len(contents)
with tarfile.open(archive, 'w') as stream:
    stream.addfile(entry, io.BytesIO(contents))
print(PurePosixPath(target).parent)
PY
    )
    # Archive extraction retains the browser UID and private read-only mode.
    docker cp --archive - "$1:$browser_fixture_directory/" < "$temporary/browser-fixture.tar"
    rm -f -- "$temporary/browser-fixture.tar"
}
