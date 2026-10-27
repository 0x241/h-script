#!/usr/bin/env python3
"""Bounded independent fixtures with separate logs and process-group cleanup."""
import argparse
import json
import os
from pathlib import Path
import re
import signal
import subprocess
import sys
import time


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser()
    parser.add_argument("--jobs", type=int, choices=(1, 2), default=2)
    parser.add_argument("--log-dir", type=Path, required=True)
    parser.add_argument("--worker", required=True)
    parser.add_argument("--image", required=True)
    parser.add_argument("--progress-fd", type=int)
    parser.add_argument("suites", nargs="+")
    args = parser.parse_args()
    if len(set(args.suites)) != len(args.suites) or any(not re.fullmatch(r"[a-z][a-z0-9_]*", name) for name in args.suites):
        parser.error("Unique suite identifiers required")
    args.log_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    if args.log_dir.is_symlink():
        parser.error("Log directory must not be a symlink")
    results = {name: {"suite": name, "status": "not_run", "exit_code": None} for name in args.suites}
    running = {}
    pending = iter(args.suites)
    failure = 0
    stopped = False
    started = time.monotonic()

    def progress(message):
        print(message, flush=True)
        if args.progress_fd is not None:
            os.write(args.progress_fd, (message + "\n").encode())

    def interrupt(signum, frame):
        raise InterruptedError(signum)

    signal.signal(signal.SIGTERM, interrupt)
    signal.signal(signal.SIGINT, interrupt)
    try:
        while running or not stopped:
            while not failure and not stopped and len(running) < args.jobs:
                name = next(pending, None)
                if name is None:
                    stopped = True
                    break
                output = (args.log_dir / (name + ".log")).open("x")
                try:
                    process = subprocess.Popen(["sh", args.worker, args.image, name], stdout=output,
                                               stderr=subprocess.STDOUT, start_new_session=True)
                except BaseException:
                    output.close()
                    raise
                running[name] = (process, output, time.monotonic())
                results[name]["status"] = "running"
                progress("suite_started=" + name)
            for name, (process, output, suite_started) in list(running.items()):
                code = process.poll()
                if code is None:
                    continue
                output.close()
                del running[name]
                results[name].update(status="passed" if code == 0 else "failed", exit_code=code,
                                     duration_seconds=round(time.monotonic() - suite_started, 3))
                progress("suite_{}={} duration_seconds={}".format(
                    "passed" if code == 0 else "failed", name, results[name]["duration_seconds"]))
                print((args.log_dir / (name + ".log")).read_text(errors="replace"), flush=True)
                if code:
                    failure = code if code > 0 else 128 - code
                    stopped = True
            if running:
                time.sleep(0.05)
    except InterruptedError as error:
        failure = 128 + error.args[0]
    finally:
        # Terminate only the new sessions created above; fixture shell traps
        # remove their own containers and networks before the job returns.
        for process, _, _ in running.values():
            if process.poll() is None:
                try:
                    os.killpg(process.pid, signal.SIGTERM)
                except ProcessLookupError:
                    pass
        for name, (process, output, suite_started) in running.items():
            try:
                process.wait(timeout=15)
            except subprocess.TimeoutExpired:
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                process.wait()
            output.close()
            results[name].update(status="interrupted", exit_code=process.returncode,
                                 duration_seconds=round(time.monotonic() - suite_started, 3))
        report = {"status": "passed" if not failure and all(r["status"] == "passed" for r in results.values()) else "failed",
                  "parallelism": args.jobs, "duration_seconds": round(time.monotonic() - started, 3),
                  "suites": list(results.values())}
        (args.log_dir / "summary.json").write_text(json.dumps(report, indent=2) + "\n")
    if failure:
        # A peer can finish later with a large successful log. Keep the actual
        # failure at the end too, where the job's bounded diagnostic tail sees it.
        for name, result in results.items():
            if result["status"] == "failed":
                print("suite_failure_details=" + name, flush=True)
                print("\n".join((args.log_dir / (name + ".log")).read_text(errors="replace").splitlines()[-60:]), flush=True)
    return failure or (0 if report["status"] == "passed" else 1)


if __name__ == "__main__":
    sys.exit(main())
