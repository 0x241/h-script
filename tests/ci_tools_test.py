import base64
import hashlib
import http.server
import json
import os
from pathlib import Path
import signal
import socket
import ssl
import subprocess
import tempfile
import threading
import time
import unittest

ROOT = Path(__file__).resolve().parent.parent
HELPER = ROOT / "ci/docker-credential-hscript-ci"
RUNNER = ROOT / "ci/run-test-suites.py"


class RegistryCredentials(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.config = Path(self.temporary.name)
        self.env = dict(os.environ, CI_REGISTRY="synthetic.invalid", CI_REGISTRY_USER="synthetic",
                        CI_REGISTRY_PASSWORD="synthetic-token-\"\\\n", DOCKER_CONFIG=str(self.config),
                        PATH=str(HELPER.parent) + os.pathsep + os.environ["PATH"])

    def helper(self, action, payload="", *args):
        return subprocess.run([str(HELPER), action, *args], input=payload, env=self.env,
                              capture_output=True, text=True, timeout=10)

    def configure(self):
        result = self.helper("configure", "", str(self.config))
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_config_has_only_helper_and_get_is_registry_scoped(self):
        self.configure()
        path = self.config / "config.json"
        original = path.read_bytes()
        self.assertEqual(path.stat().st_mode & 0o777, 0o600)
        self.assertEqual(json.loads(original), {"credHelpers": {"synthetic.invalid": "hscript-ci"}})
        result = self.helper("get", "synthetic.invalid\n")
        self.assertEqual(json.loads(result.stdout), {"Username": "synthetic", "Secret": self.env["CI_REGISTRY_PASSWORD"]})
        self.assertEqual(json.loads(self.helper("list").stdout), {"synthetic.invalid": "synthetic"})
        for registry in ("other.invalid", "synthetic.invalid.attacker", "https://synthetic.invalid/other", ""):
            result = self.helper("get", registry + "\n")
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(result.stdout, "")
            self.assertNotIn("synthetic-token", result.stderr)
        self.assertNotEqual(self.helper("store", '{"Secret":"must-not-persist"}').returncode, 0)
        self.assertNotEqual(self.helper("configure", "", str(self.config)).returncode, 0)
        self.assertEqual(path.read_bytes(), original)
        self.assertEqual([p.name for p in self.config.iterdir()], ["config.json"])

    def test_missing_credentials_and_symlink_rejected(self):
        del self.env["CI_REGISTRY_PASSWORD"]
        self.assertNotEqual(self.helper("get", "synthetic.invalid\n").returncode, 0)
        self.env["CI_REGISTRY_PASSWORD"] = "synthetic"
        link = self.config / "link"
        link.symlink_to(self.config, target_is_directory=True)
        self.assertNotEqual(self.helper("configure", "", str(link)).returncode, 0)

    @unittest.skipUnless(os.environ.get("H_SCRIPT_DOCKER_AUTH_TEST") == "1", "opt-in local Docker CLI registry fixture")
    def test_real_docker_cli_reads_helper_without_login(self):
        authorized = []
        requests = []
        self.env["CI_REGISTRY_PASSWORD"] = "synthetic-registry-password"
        expected = "Basic " + base64.b64encode(b"synthetic:synthetic-registry-password").decode()
        manifest_type = "application/vnd.docker.distribution.manifest.v2+json"
        config = json.dumps({"architecture": "amd64", "os": "linux", "config": {},
                             "rootfs": {"type": "layers", "diff_ids": []}}).encode()
        payload = json.dumps({"schemaVersion": 2, "mediaType": manifest_type,
                              "config": {"mediaType": "application/vnd.docker.container.image.v1+json",
                                         "size": len(config), "digest": "sha256:" + hashlib.sha256(config).hexdigest()}, "layers": []}).encode()

        class Registry(http.server.BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_GET(self):
                requests.append((self.command, self.path, self.headers.get("Authorization") == expected))
                if self.headers.get("Authorization") != expected:
                    self.send_response(401)
                    self.send_header("WWW-Authenticate", 'Basic realm="synthetic"')
                    self.send_header("Content-Length", "0")
                    self.end_headers()
                    return
                authorized.append(self.path)
                data = b"{}" if self.path == "/v2/" else (config if "/blobs/" in self.path else payload)
                self.send_response(200)
                self.send_header("Docker-Distribution-Api-Version", "registry/2.0")
                self.send_header("Content-Type", manifest_type if "/manifests/" in self.path else "application/json")
                self.send_header("Docker-Content-Digest", "sha256:" + hashlib.sha256(data).hexdigest())
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                if self.command != "HEAD":
                    self.wfile.write(data)

            do_HEAD = do_GET

        # Docker CLI and Buildx versions differ in loopback HTTP/TLS selection.
        # Support both on this ephemeral loopback-only fixture; production
        # transport and registry trust configuration are never changed.
        certificate = self.config / "fixture.crt"
        private_key = self.config / "fixture.key"
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                        "-subj", "/CN=127.0.0.1", "-addext", "subjectAltName=IP:127.0.0.1",
                        "-keyout", str(private_key), "-out", str(certificate)],
                       check=True, capture_output=True, timeout=20)
        private_key.chmod(0o600)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(certificate, private_key)
        self.env["SSL_CERT_FILE"] = str(certificate)

        class LoopbackRegistryServer(http.server.ThreadingHTTPServer):
            def get_request(self):
                connection, address = super().get_request()
                connection.settimeout(5)
                if connection.recv(1, socket.MSG_PEEK) == b"\x16":
                    connection = context.wrap_socket(connection, server_side=True)
                return connection, address

        server = LoopbackRegistryServer(("127.0.0.1", 0), Registry)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        registry = "127.0.0.1:" + str(server.server_port)
        self.env["CI_REGISTRY"] = registry
        self.configure()
        # Desktop may install Buildx only under the user's normal config.
        # Preserve plugin discovery without copying that config or its auths.
        plugin = subprocess.run(["docker", "info", "--format",
                                 '{{range .ClientInfo.Plugins}}{{if eq .Name "buildx"}}{{.Path}}{{end}}{{end}}'],
                                capture_output=True, text=True, timeout=20)
        self.assertEqual(plugin.returncode, 0, plugin.stderr)
        buildx = Path(plugin.stdout.strip())
        self.assertTrue(buildx.is_file(), "Installed Docker Buildx plugin required")
        plugins = self.config / "cli-plugins"
        plugins.mkdir()
        (plugins / "docker-buildx").symlink_to(buildx)
        for command in (["docker", "manifest", "inspect", "--insecure"],
                        ["docker", "buildx", "imagetools", "inspect", "--raw"]):
            result = subprocess.run([*command, registry + "/synthetic:fixture"],
                                    env=self.env, capture_output=True, text=True, timeout=20)
            self.assertEqual(result.returncode, 0, result.stderr + repr(requests))
        self.assertTrue(authorized)
        self.assertNotIn("auths", (self.config / "config.json").read_text())
        self.assertNotIn(self.env["CI_REGISTRY_PASSWORD"], (self.config / "config.json").read_text())


class SuiteRunner(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.worker = self.root / "worker.sh"
        self.worker.write_text('''#!/bin/sh
exec python3 - "$2" "$EVENTS" <<'PY'
import json,os,signal,sys,time
name,path=sys.argv[1:]
def record(kind):
    with open(path,'a') as out:out.write(json.dumps({'name':name,'kind':kind,'time':time.monotonic()})+'\\n')
def stopped(*args):
    record('cleanup');sys.exit(143)
signal.signal(signal.SIGTERM,stopped)
record('start')
time.sleep(0.05 if name=='fails' else (30 if name=='held' else 0.2))
record('end')
if name=='fails':print('synthetic suite failure')
sys.exit(19 if name=='fails' else 0)
PY
''')
        self.env = dict(os.environ, EVENTS=str(self.root / "events.jsonl"))

    def command(self, jobs, *names):
        return ["python3", str(RUNNER), "--jobs", str(jobs), "--log-dir", str(self.root / "logs"),
                "--worker", str(self.worker), "--image", "synthetic", *names]

    def test_parallelism_is_bounded_and_all_suites_remain(self):
        result = subprocess.run(self.command(2, "one", "two", "three"), env=self.env, capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        events = [json.loads(line) for line in Path(self.env["EVENTS"]).read_text().splitlines()]
        active = peak = 0
        for event in sorted(events, key=lambda e: e["time"]):
            active += 1 if event["kind"] == "start" else -1
            peak = max(peak, active)
        self.assertEqual((peak, active), (2, 0))
        report = json.loads((self.root / "logs/summary.json").read_text())
        self.assertEqual(report["status"], "passed")
        self.assertEqual([r["suite"] for r in report["suites"]], ["one", "two", "three"])
        for path in (self.root / "logs").iterdir():
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)

    def test_failure_stops_new_work_and_is_not_success(self):
        result = subprocess.run(self.command(2, "fails", "one", "never"), env=self.env, capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 19)
        self.assertTrue(result.stdout.rstrip().endswith('suite_failure_details=fails\nsynthetic suite failure'))
        report = json.loads((self.root / "logs/summary.json").read_text())
        self.assertEqual(report["status"], "failed")
        self.assertEqual(report["suites"][-1]["status"], "not_run")

    def test_serial_fallback_and_invalid_parallelism(self):
        result = subprocess.run(self.command(3, "never"), env=self.env, capture_output=True, text=True, timeout=10)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(Path(self.env["EVENTS"]).exists())
        result = subprocess.run(self.command(1, "one", "two"), env=self.env, capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        events = [json.loads(line) for line in Path(self.env["EVENTS"]).read_text().splitlines()]
        self.assertEqual([(e["name"], e["kind"]) for e in events],
                         [("one", "start"), ("one", "end"), ("two", "start"), ("two", "end")])

    def test_cancel_waits_for_owned_worker_cleanup(self):
        process = subprocess.Popen(self.command(2, "held", "one", "never"), env=self.env, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        try:
            deadline = time.monotonic() + 5
            while time.monotonic() < deadline:
                if Path(self.env["EVENTS"]).exists() and any(
                    e["name"] == "held" and e["kind"] == "start"
                    for e in map(json.loads, Path(self.env["EVENTS"]).read_text().splitlines())
                ):
                    break
                time.sleep(0.01)
            self.assertTrue(Path(self.env["EVENTS"]).exists())
            process.send_signal(signal.SIGTERM)
            _, error = process.communicate(timeout=10)
            self.assertEqual(process.returncode, 143, error)
            events = [json.loads(line) for line in Path(self.env["EVENTS"]).read_text().splitlines()]
            self.assertTrue(any(e["name"] == "held" and e["kind"] == "cleanup" for e in events))
            report = json.loads((self.root / "logs/summary.json").read_text())
            self.assertEqual(report["status"], "failed")
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()
