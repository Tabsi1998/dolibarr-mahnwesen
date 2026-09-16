#!/usr/bin/env python3
"""Run every check the CI runs, on this computer - and the ones it skips.

GitHub is the second, independent confirmation. This is the first: every job of
.github/workflows/ci.yml and of release-verify.yml, with the developer's
own tools and processor, plus the gates the CI never runs.

Groups:
    repository  every shell script parses, no CRLF stored, whitespace across
                every tracked line, Gitleaks over the history and over
                uncommitted and new files
    php         scripts/check-module.sh on PHP 7.4, 8.1, 8.2, 8.3 and 8.4 -
                the CI matrix, in the official PHP images - and proof that the
                lint and the policy tests really ran
    dolibarr    the source and API compatibility check against Dolibarr 21.0 to
                24.0
    package     scripts/build_release.py from the working copy and from the
                snapshot: byte for byte identical, checked file by file, named
                the way Dolibarr's installer accepts
    release     scripts/release.py --metadata: version, dated changelog section
                and support matrix agree; any tag matches; a package changed
                since the newest release needs a new version
    runtime     the module in a running Dolibarr 21, 22, 23 and 24 - official
                images with MariaDB and Mailpit - installed from the package
                through Dolibarr's installer, upgraded from the previous
                release, driven through its pages and the Dolibarr cron:
                activation, synchronisation, access rules,
                a real send with the attachment hashes checked against the
                delivered bytes, automatic sending, and PHP messages from
                module code (tests/runtime/)
    extra       what GitHub does not run: PHP 8.4 deprecations in the policy
                tests, placeholders that differ between the German and English
                texts, ShellCheck, and OSV over the lockfiles

Usage:
    python scripts/local_check.py                  everything but extra
    python scripts/local_check.py --all            everything
    python scripts/local_check.py --only php       some groups
    python scripts/local_check.py --list           the steps, without running
    python scripts/local_check.py --record         refresh the ratchet baseline

Results go to .local-testing/, which Git ignores.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import platform
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
import zipfile
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Callable

ROOT = Path(__file__).resolve().parents[1]
STATE = ROOT / ".local-testing"
LOGS = STATE / "logs"
BASELINE = ROOT / "scripts" / "ci-baseline.json"
WINDOWS = platform.system() == "Windows"

GROUPS = ("repository", "php", "dolibarr", "package", "release", "runtime", "extra")
DEFAULT_GROUPS = ("repository", "php", "dolibarr", "package", "release", "runtime")

# The CI matrices. The lowest entry of each must match what the module declares.
PHP_VERSIONS = ("7.4", "8.1", "8.2", "8.3", "8.4")
DOLIBARR_VERSIONS = ("21.0", "22.0", "23.0", "24.0")
RELEASE_PHP = "8.2"

# The running Dolibarr of each supported version: the official image, pinned to
# a release, and the host ports of its web server and of its Mailpit.
RUNTIME_IMAGES = {
    "21.0": ("dolibarr/dolibarr:21.0.4", 18021, 18121),
    "22.0": ("dolibarr/dolibarr:22.0.5", 18022, 18122),
    "23.0": ("dolibarr/dolibarr:23.0.4", 18023, 18123),
    "24.0": ("local-ci/dolibarr:24.0.1", 18024, 18124),
}
# Releases without an official image yet, built from Dolibarr's own docker
# repository at a pinned commit. 24.0.0 ends every cron run with a fatal error
# in Dolibarr's cron shutdown handler (Dolibarr #39801, fixed in 24.0.1).
RUNTIME_BUILDS = {
    "local-ci/dolibarr:24.0.1": "https://github.com/Dolibarr/dolibarr-docker.git"
                                "#ec6b10487e52244b64142b6d8806eb26409ac406:images/24.0.1-php8.2",
}
MARIADB_IMAGE = "mariadb:11.4.13"
MAILPIT_IMAGE = "axllent/mailpit:v1.31.1"
RUNTIME_TESTS = ROOT / "tests" / "runtime"

SNAPSHOT = STATE / "snapshot"
PACKAGE_OUT = STATE / "package"

# The lines scripts/check-module.sh prints when each part really ran. It skips
# the lint and the policy tests silently when php is missing, so exit code 0
# alone does not prove they ran.
MODULE_MARKERS = ("PHP lint: OK", "Policy tests: OK", "Language keys: OK",
                  "Invoice write guard: OK", "Security contracts: OK")


# =============================================================================
# Shared core
#
# Every repository's local_check.py carries the same copy of this part. The
# header above it names the paths and the groups, the steps below it say what
# is checked, and nothing in here knows which repository it is in. Each copy
# stands on its own, so a repository can be checked right after a fresh clone.
# =============================================================================

PASS, FAIL, SKIP = "passed", "failed", "skipped"

# The MongoDB the CI workflows run as a service.
MONGO_IMAGE = "mongo:7.0.39-jammy"
# OSV-Scanner 2.6.0 and ShellCheck 0.11.0, pinned by digest: a scanner that
# updates itself between two runs would change the findings on its own.
OSV_IMAGE = "ghcr.io/google/osv-scanner@sha256:afd838850ac1a0fcc15ff4a041dc9ba11123c3f0d2666217a5f0fcf9222b55fa"
SHELLCHECK_IMAGE = "koalaman/shellcheck@sha256:bb596a0d169b85ddd81d8b6d3a2ff6d5baf5fca10b97f575ebc647c3dff62b3d"

# Git's well-known id of the empty tree. A diff against it covers every line.
EMPTY_TREE = "4b825dc642cb6eb9a060e54bf8d69288fbee4904"

TOOLCHAIN = Path.home() / ".local-toolchain"
PROGRESS = "local-check.progress.json"
REPORT = "local-check.json"

# Paths that belong to the local checks, never to the product. A snapshot of
# the repository leaves them out, so they cannot end up in a build or a scan.
LOCAL_TOOLING = (".ci-panel/", ".local-testing/")

# Variables whose names look like credentials. No check needs a real one, and a
# shell that happens to carry a live token or database password must not hand
# it to code under test. Only their names are printed.
SECRET_NAME = re.compile(
    r"TOKEN|SECRET|PASSW|CREDENTIAL|PRIVATE|API_?KEY|ENCRYPTION|_KEY$"
    r"|^(MONGO|SMTP|STRIPE|RESEND|JWT|DISCORD|ADMIN|DOLIBARR)_",
    re.IGNORECASE,
)


class StepFailed(Exception):
    """A step found a problem. The message says what, and how to fix it."""


class StepSkipped(Exception):
    """A step cannot run here. The message says why."""


@dataclass
class Step:
    group: str
    name: str
    describe: str
    action: Callable[["Context"], "str | None"]
    # Names of steps that must pass first. A bare name means the same group;
    # "group/name" reaches into another one.
    needs: tuple = ()

    @property
    def key(self) -> str:
        return f"{self.group}/{self.name}"

    def requirements(self) -> list[str]:
        return [need if "/" in need else f"{self.group}/{need}" for need in self.needs]


@dataclass
class Result:
    group: str
    name: str
    describe: str
    status: str
    detail: str = ""
    seconds: float = 0.0


@dataclass
class Context:
    env: dict
    dropped: list
    log_dir: Path
    record: bool = False
    containers: list = field(default_factory=list)
    processes: list = field(default_factory=list)
    cache: dict = field(default_factory=dict)

    def log(self, name: str, text: str) -> Path:
        self.log_dir.mkdir(parents=True, exist_ok=True)
        path = self.log_dir / f"{name}.log"
        path.write_text(text, encoding="utf-8", errors="replace")
        return path

    def run(self, *arguments, cwd: Path | None = None, env: dict | None = None,
            check: bool = True, timeout: int = 1800,
            stdin: str | None = None) -> subprocess.CompletedProcess:
        """Run one command in the cleaned environment and capture its output."""
        merged = dict(self.env)
        if env:
            merged.update(env)
        command = [str(part) for part in arguments]
        try:
            completed = subprocess.run(
                command, cwd=str(cwd or ROOT), env=merged, input=stdin,
                capture_output=True, text=True, encoding="utf-8", errors="replace",
                timeout=timeout)
        except FileNotFoundError as error:
            raise StepSkipped(f"{command[0]} is not installed: {error}") from error
        except subprocess.TimeoutExpired as error:
            raise StepFailed(f"{Path(command[0]).name} did not finish within {timeout} s") from error
        if check and completed.returncode != 0:
            raise StepFailed(tail(completed))
        return completed


def clean_environment(source: dict) -> tuple[dict, list]:
    """Split an environment into what the steps get and the names withheld."""
    kept, withheld = {}, []
    for name, value in source.items():
        if SECRET_NAME.search(name):
            withheld.append(name)
        else:
            kept[name] = value
    return kept, sorted(withheld)


def base_context(record: bool = False) -> Context:
    env, withheld = clean_environment(dict(os.environ))
    # GitHub sets CI. Some tools behave differently with it - Create React App
    # turns lint warnings into errors - so the local run sets it too.
    env["CI"] = "true"
    env.setdefault("PYTHONUTF8", "1")
    env["npm_config_fund"] = "false"
    env["npm_config_update_notifier"] = "false"
    env["COREPACK_ENABLE_DOWNLOAD_PROMPT"] = "0"
    env["DOTNET_CLI_TELEMETRY_OPTOUT"] = "1"
    STATE.mkdir(parents=True, exist_ok=True)
    return Context(env=env, dropped=withheld, log_dir=LOGS, record=record)


def tail(completed: subprocess.CompletedProcess, lines: int = 25) -> str:
    """The end of a failing command's output, which is where the reason is."""
    text = (completed.stdout or "") + (completed.stderr or "")
    kept = [line for line in text.splitlines() if line.strip()][-lines:]
    return "\n".join(kept) or f"exit code {completed.returncode}"


def tool(context: Context, name: str) -> str | None:
    return shutil.which(name, path=context.env.get("PATH"))


def require(context: Context, name: str, hint: str) -> str:
    found = tool(context, name)
    if not found:
        raise StepSkipped(f"{name} is not installed. {hint}")
    return found


def git(context: Context) -> str:
    return require(context, "git", "Install Git for Windows.")


def tracked(context: Context, *pathspecs: str, new: bool = False) -> list[str]:
    """Files Git knows, optionally with the new ones it would pick up."""
    arguments = ["ls-files", "-z", "--cached"]
    if new:
        arguments += ["--others", "--exclude-standard"]
    listed = context.run(git(context), *arguments, "--", *pathspecs).stdout.split("\0")
    return sorted({name for name in listed if name and (ROOT / name).is_file()})


def posix_bash(context: Context) -> str:
    """A real POSIX bash, never the WSL stub.

    System32\\bash.exe is the WSL launcher. It comes first on PATH in a plain
    PowerShell, it cannot read a Windows path, and it fails every script handed
    to it. A check that calls every deployment script broken teaches the
    developer to ignore it, so the stub is refused by name.
    """
    for folder in (r"C:\Program Files\Git\bin", r"C:\Program Files\Git\usr\bin"):
        candidate = Path(folder) / "bash.exe"
        if candidate.is_file():
            return str(candidate)
    found = tool(context, "bash")
    if found and "system32" not in found.lower():
        return found
    raise StepSkipped("no POSIX bash was found. Git for Windows ships one.")


def port_open(port: int) -> bool:
    with socket.socket() as probe:
        probe.settimeout(0.4)
        return probe.connect_ex(("127.0.0.1", port)) == 0


def docker(context: Context) -> str:
    if "docker" in context.cache:
        return context.cache["docker"]
    binary = require(context, "docker", "Install Docker Desktop.")
    probe = context.run(binary, "info", "--format", "{{.ServerVersion}}", check=False, timeout=90)
    if probe.returncode != 0:
        raise StepSkipped("the Docker engine is not running. Start Docker Desktop.")
    context.cache["docker"] = binary
    return binary


# ------------------------------------------------------------------ runtimes

def python_for(context: Context, version: str) -> str:
    """The interpreter of one Python version, found through the py launcher."""
    key = f"python-{version}"
    if key in context.cache:
        return context.cache[key]
    found = None
    launcher = tool(context, "py")
    if launcher:
        probe = context.run(launcher, f"-{version}", "-c", "import sys; print(sys.executable)",
                            check=False, timeout=120)
        if probe.returncode == 0 and probe.stdout.strip():
            found = probe.stdout.strip().splitlines()[-1]
    found = found or tool(context, f"python{version}")
    if not found:
        raise StepSkipped(f"Python {version} is not installed. winget install Python.Python.{version}")
    context.cache[key] = found
    return found


def venv_executable(folder: Path) -> Path:
    return folder / ("Scripts/python.exe" if WINDOWS else "bin/python")


def make_venv(context: Context, folder: Path, interpreter: str, *pip_arguments) -> str:
    """An environment of its own, so a result cannot depend on what else is installed."""
    exe = venv_executable(folder)
    if not exe.is_file():
        context.run(interpreter, "-m", "venv", folder, timeout=900)
    completed = context.run(exe, "-m", "pip", "install", "--quiet", "--disable-pip-version-check",
                            "--upgrade", "pip", *pip_arguments, timeout=3600)
    context.log(f"pip-{folder.name}", completed.stdout + completed.stderr)
    return str(exe)


def node_of(context: Context, major: int) -> str:
    """A Node of one major version: the pinned one, or the one on PATH if it matches.

    A newer Node must not quietly stand in for the one the CI uses. The runtime
    differences that break a deployment are the ones a version jump hides.
    """
    key = f"node-{major}"
    if key in context.cache:
        return context.cache[key]
    found = None
    executable = "node.exe" if WINDOWS else "node"
    if TOOLCHAIN.is_dir():
        for child in sorted(TOOLCHAIN.iterdir(), reverse=True):
            if child.is_dir() and child.name.startswith(f"node-v{major}."):
                for candidate in (child / executable, child / "bin" / executable):
                    if candidate.is_file():
                        found = str(candidate)
                        break
            if found:
                break
    if not found:
        system = tool(context, "node")
        if system:
            version = context.run(system, "--version", check=False, timeout=60).stdout.strip()
            if version.lstrip("v").split(".")[0] == str(major):
                found = system
    if not found:
        raise StepSkipped(f"Node {major} is not installed. Unpack node-v{major}.x into {TOOLCHAIN}")
    context.cache[key] = found
    return found


def npm_of(node: str) -> str:
    candidate = Path(node).with_name("npm.cmd" if WINDOWS else "npm")
    return str(candidate) if candidate.is_file() else "npm"


def node_path_env(context: Context, node: str) -> dict:
    """An environment in which that node, its npm and its corepack come first."""
    return {"PATH": os.pathsep.join([str(Path(node).parent), context.env.get("PATH", "")])}


def run_yarn(context: Context, cwd: Path, *arguments, node: str, check: bool = True,
             timeout: int = 3600) -> subprocess.CompletedProcess:
    """Yarn 1.22 through Corepack, with the chosen Node first on PATH.

    The lockfile is yarn.lock, so npm must never stand in: it would resolve a
    different tree than the one the deployment installs.
    """
    env = node_path_env(context, node)
    direct = shutil.which("yarn", path=env["PATH"])
    if direct:
        command = [direct]
    else:
        corepack = shutil.which("corepack", path=env["PATH"])
        if not corepack:
            raise StepSkipped("neither yarn nor corepack was found; Node ships corepack")
        command = [corepack, "yarn"]
    return context.run(*command, *arguments, cwd=cwd, env=env, check=check, timeout=timeout)


# ------------------------------------------------------------------ services

def start_mongo(context: Context, name: str, port: int, image: str = MONGO_IMAGE) -> str:
    """A MongoDB of the CI's image, in a container of its own on a port of its own.

    A fresh container per run means no test ever sees the previous run's data,
    and a port per repository means two repositories can be checked at once.
    """
    key = f"mongo:{name}"
    if key in context.cache:
        return context.cache[key]
    binary = docker(context)
    context.run(binary, "rm", "--force", name, check=False, timeout=120)
    if port_open(port):
        raise StepSkipped(f"port {port} is taken by something else; stop it and run again")
    context.run(binary, "run", "--detach", "--name", name, "--publish",
                f"127.0.0.1:{port}:27017", image, timeout=1800)
    context.containers.append(name)
    deadline = time.time() + 120
    while time.time() < deadline:
        ping = context.run(binary, "exec", name, "mongosh", "--quiet", "--eval",
                           "db.adminCommand({ping: 1}).ok", check=False, timeout=60)
        if ping.returncode == 0 and ping.stdout.strip().endswith("1"):
            url = f"mongodb://127.0.0.1:{port}"
            context.cache[key] = url
            return url
        time.sleep(2)
    raise StepFailed(f"{image} did not answer a ping within 120 s")


def start_process(context: Context, name: str, arguments: list, *, cwd: Path, env: dict,
                  url: str, seconds: int = 120, tls=None) -> None:
    """Start a server in the background and wait until it answers HTTP at all.

    Any HTTP answer counts, a 404 included: the question here is whether the
    process serves, and the checks that follow judge what it serves. For a
    server with a certificate of its own, tls is the ssl context that trusts it.
    """
    context.log_dir.mkdir(parents=True, exist_ok=True)
    path = context.log_dir / f"{name}.log"
    sink = path.open("w", encoding="utf-8", errors="replace")
    merged = dict(context.env)
    merged.update(env)
    process = subprocess.Popen([str(part) for part in arguments], cwd=str(cwd), env=merged,
                               stdout=sink, stderr=subprocess.STDOUT)
    context.processes.append((process, sink))
    deadline = time.time() + seconds
    while time.time() < deadline:
        if process.poll() is not None:
            sink.flush()
            output = path.read_text(encoding="utf-8", errors="replace")
            raise StepFailed(f"{name} exited with code {process.returncode} before it answered. "
                             f"Log: {path}\n{output[-2000:]}")
        try:
            with urllib.request.urlopen(url, timeout=5, context=tls):
                return
        except urllib.error.HTTPError:
            return
        except OSError:
            time.sleep(1)
    raise StepFailed(f"{name} did not answer {url} within {seconds} s. Log: {path}")


def stop_everything(context: Context) -> None:
    for process, sink in reversed(context.processes):
        if process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=20)
            except subprocess.TimeoutExpired:
                process.kill()
        sink.close()
    context.processes.clear()
    binary = shutil.which("docker", path=context.env.get("PATH"))
    for name in reversed(context.containers):
        if binary:
            subprocess.run([binary, "rm", "--force", name], capture_output=True, text=True,
                           timeout=180)
    context.containers.clear()


def snapshot(context: Context, target: Path, *pathspecs: str, linux: bool = False) -> int:
    """Copy what Git would commit into target: tracked files as they are now, and new ones.

    Ignored files stay behind - a local .env with real credentials, a virtual
    environment, build output - and so do the local check's own files. With
    linux=True, text files get the LF endings a checkout on the Linux runner
    has, because bash in a container stops at the first CR.
    """
    if target.exists():
        shutil.rmtree(target)
    count = 0
    for name in tracked(context, *pathspecs, new=True):
        if name.startswith(LOCAL_TOOLING):
            continue
        data = (ROOT / name).read_bytes()
        if linux and b"\r\n" in data and b"\0" not in data[:8000]:
            data = data.replace(b"\r\n", b"\n")
        destination = target / name
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(data)
        count += 1
    return count


# ------------------------------------------------------------------- ratchet

def load_baseline() -> dict:
    try:
        return json.loads(BASELINE.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}


def save_baseline(data: dict) -> None:
    BASELINE.parent.mkdir(parents=True, exist_ok=True)
    BASELINE.write_text(json.dumps(data, indent=2, sort_keys=True, ensure_ascii=False) + "\n",
                        encoding="utf-8", newline="\n")


def ratchet(context: Context, key: str, found: set, what: str) -> str:
    """Known findings are debt; new ones fail.

    A gate is green on the day it is switched on and honest from then on. Run
    with --record once debt has been paid down, so the baseline shrinks with it.
    """
    baseline = load_baseline()
    known = set(baseline.get(key, []))
    if context.record:
        baseline[key] = sorted(found)
        save_baseline(baseline)
        return f"baseline recorded: {len(found)} {what}"
    new = sorted(found - known)
    if new:
        shown = "\n  ".join(new[:20]) + ("\n  ..." if len(new) > 20 else "")
        raise StepFailed(f"{len(new)} new {what}:\n  {shown}\n"
                         f"Fix them, or run with --record to accept them into {BASELINE.name}.")
    note = f"{len(found)} known {what}" if found else f"no {what}"
    resolved = known - found
    if resolved:
        note += f"; {len(resolved)} resolved since the baseline, run --record to lock that in"
    return note


# ------------------------------------------------------ steps every repo shares

def shell_scripts(context: Context) -> str:
    """Every shell script parses, with the line endings Git stores.

    The CI checks the scripts it names. This checks every .sh in the repository:
    a restore or deploy script that only breaks during an incident is the worst
    place to find a typo.
    """
    bash = posix_bash(context)
    listed = tracked(context, "*.sh", new=True)
    if not listed:
        raise StepSkipped("no shell scripts in this repository")
    broken = []
    for name in listed:
        text = (ROOT / name).read_bytes().replace(b"\r\n", b"\n").decode("utf-8", "replace")
        completed = context.run(bash, "-n", check=False, timeout=120, stdin=text)
        if completed.returncode != 0:
            broken.append(f"{name}: {tail(completed, 2)}")
    if broken:
        raise StepFailed("these do not parse:\n  " + "\n  ".join(broken))
    return f"{len(listed)} scripts parse"


def line_endings(context: Context) -> str:
    """No CRLF in what Git stores for text files.

    Git for Windows converts line endings on the way out, so a file can look
    right here and still be stored with CRLF - and a shell script stored that
    way fails on a Linux server with 'bad interpreter'. This reads the index,
    which is what every other machine checks out.
    """
    found = set()
    for entry in context.run(git(context), "ls-files", "--eol", "-z").stdout.split("\0"):
        info, _, path = entry.partition("\t")
        fields = info.split()
        if not path or not fields:
            continue
        attributes = info.partition("attr/")[2].strip()
        if fields[0] in ("i/crlf", "i/mixed") and "-text" not in attributes:
            found.add(f"{path} ({fields[0][2:]})")
    return ratchet(context, "line-endings", found, "text files stored with CRLF")


def whitespace(context: Context) -> str:
    """git diff --check over everything, not over nothing.

    On a fresh checkout there is no diff, so a CI that runs git diff --check can
    never fail. Measured against the empty tree it covers every committed line:
    what is there today is recorded, and whitespace errors in uncommitted
    changes fail outright.
    """
    binary = git(context)
    committed = context.run(binary, "diff", "--check", EMPTY_TREE, "HEAD", check=False, timeout=900)
    found = set()
    for line in committed.stdout.splitlines():
        match = re.match(r"^(.+?):\d+: (.+?)\.?$", line)
        if match:
            found.add(f"{match.group(1)}: {match.group(2)}")
    pending = context.run(binary, "diff", "--check", "HEAD", check=False, timeout=900)
    fresh = [line for line in pending.stdout.splitlines() if re.match(r"^.+?:\d+: ", line)]
    if fresh:
        raise StepFailed("whitespace errors in uncommitted changes:\n  " + "\n  ".join(fresh[:20]))
    return ratchet(context, "whitespace", found, "committed whitespace errors")


def gitleaks_binary(context: Context) -> str:
    return require(context, "gitleaks", "winget install Gitleaks.Gitleaks")


def gitleaks_config() -> list[str]:
    config = ROOT / ".gitleaks.toml"
    return ["--config", str(config)] if config.is_file() else []


def gitleaks_history(context: Context) -> str:
    completed = context.run(gitleaks_binary(context), "git", ".", "--redact", "--no-banner",
                            *gitleaks_config(), "--log-opts=HEAD", check=False, timeout=1800)
    output = completed.stdout + completed.stderr
    context.log("gitleaks-history", output)
    if completed.returncode != 0:
        raise StepFailed("Gitleaks found secrets in the reachable history:\n" + tail(completed))
    commits = re.search(r"(\d+) commits scanned", output)
    return f"{commits.group(1) if commits else 'every'} commits are clean"


def gitleaks_worktree(context: Context) -> str:
    """The files Git does not have yet: changed ones and new ones.

    The history scan cannot see them, and neither can GitHub. Only what Git
    would pick up is scanned - ignored logs and build output are left out - so
    a finding here is always something that could be committed next.
    """
    binary = git(context)
    changed = set(context.run(binary, "diff", "--name-only", "-z", "HEAD").stdout.split("\0"))
    changed |= set(context.run(binary, "ls-files", "-z", "--others", "--exclude-standard").stdout.split("\0"))
    pending = sorted(name for name in changed
                     if name and not name.startswith(LOCAL_TOOLING) and (ROOT / name).is_file())
    if not pending:
        return "nothing uncommitted to scan"
    with tempfile.TemporaryDirectory(prefix="local-check-leaks-") as folder:
        for name in pending:
            destination = Path(folder) / name
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / name, destination)
        completed = context.run(gitleaks_binary(context), "dir", ".", "--redact", "--no-banner",
                                *gitleaks_config(), cwd=Path(folder), check=False, timeout=1800)
    context.log("gitleaks-worktree", completed.stdout + completed.stderr)
    if completed.returncode != 0:
        raise StepFailed("Gitleaks found secrets in uncommitted or new files:\n" + tail(completed))
    return f"{len(pending)} uncommitted or new files are clean"


def osv_scan(context: Context) -> str:
    """Known vulnerabilities in every lockfile, from the OSV database.

    One scanner reads package-lock.json, yarn.lock, requirements files, go.sum
    and NuGet locks alike, so every ecosystem in the repository is judged by
    the same source of advisories. Transitive resolution stays off: for a
    requirements file without a lock it picks the oldest allowed versions -
    h11 0.9.0 where 0.16.0 is installed - and reports advisories that do not
    apply.
    """
    binary = docker(context)
    completed = context.run(binary, "run", "--rm", "--mount",
                            f"type=bind,source={ROOT},target=/src,readonly", OSV_IMAGE,
                            "scan", "source", "--recursive", "--no-resolve", "--allow-no-lockfiles",
                            "--format", "json", "/src",
                            check=False, timeout=2400)
    output = completed.stdout + completed.stderr
    context.log("osv-scanner", output)
    if "No package sources found" in output:
        return "no lockfiles in this repository, nothing to scan"
    if completed.returncode not in (0, 1):
        raise StepFailed("OSV-Scanner could not scan the repository:\n" + tail(completed))
    try:
        report = json.loads(completed.stdout or "{}")
    except ValueError as error:
        raise StepFailed("OSV-Scanner produced no JSON report:\n" + tail(completed)) from error
    found = set()
    for result in report.get("results") or []:
        source = str((result.get("source") or {}).get("path", "")).replace("/src/", "", 1)
        for package in result.get("packages") or []:
            info = package.get("package") or {}
            for vulnerability in package.get("vulnerabilities") or []:
                found.add(f"{source}: {info.get('name')} {info.get('version')} {vulnerability.get('id')}")
    return ratchet(context, "osv", found, "known vulnerabilities in the lockfiles")


def shellcheck(context: Context) -> str:
    """ShellCheck over every tracked script: quoting, globbing and exit-code traps."""
    listed = tracked(context, "*.sh")
    if not listed:
        raise StepSkipped("no shell scripts in this repository")
    binary = docker(context)
    completed = context.run(binary, "run", "--rm", "--mount",
                            f"type=bind,source={ROOT},target=/mnt,readonly", SHELLCHECK_IMAGE,
                            "--format=json1", *[f"/mnt/{name}" for name in listed],
                            check=False, timeout=900)
    try:
        report = json.loads(completed.stdout or '{"comments": []}')
    except ValueError as error:
        raise StepFailed("ShellCheck produced no report:\n" + tail(completed)) from error
    found = {f"{comment['file'][5:]}: SC{comment['code']} ({comment['level']})"
             for comment in report.get("comments", [])}
    return ratchet(context, "shellcheck", found, "ShellCheck findings")


def pytest_counts(text: str) -> dict:
    """The counts from pytest's last summary line."""
    lines = [line for line in text.splitlines()
             if re.search(r"\d+ (passed|failed|skipped|errors?|deselected)", line)]
    counts: dict = {}
    if lines:
        for number, kind in re.findall(r"(\d+) (passed|failed|skipped|errors?|deselected)", lines[-1]):
            counts["errors" if kind.startswith("error") else kind] = int(number)
    return counts


def describe_counts(counts: dict) -> str:
    order = ("passed", "failed", "errors", "skipped", "deselected")
    return ", ".join(f"{counts[kind]} {kind}" for kind in order if counts.get(kind)) or "no tests reported"


# -------------------------------------------------------------------- runner

def head_info() -> dict:
    binary = shutil.which("git")
    if not binary:
        return {}

    def ask(*arguments: str) -> str:
        completed = subprocess.run([binary, *arguments], cwd=str(ROOT), capture_output=True,
                                   text=True, encoding="utf-8", errors="replace")
        return completed.stdout.strip()

    return {"branch": ask("branch", "--show-current"), "head": ask("rev-parse", "--short", "HEAD"),
            "subject": ask("log", "-1", "--format=%s"), "dirty": bool(ask("status", "--porcelain"))}


def write_progress(planned: list, results: list, current, started: str, finished: bool,
                   info: dict) -> None:
    """What has run so far, for the dashboard. Best effort: a locked file is skipped."""
    done = {(item.group, item.name): item for item in results}
    steps = []
    for step in planned:
        item = done.get((step.group, step.name))
        if item:
            steps.append({"group": item.group, "name": item.name, "describe": item.describe,
                          "status": item.status, "detail": item.detail, "seconds": item.seconds})
        else:
            steps.append({"group": step.group, "name": step.name, "describe": step.describe,
                          "status": "running" if step is current else "pending",
                          "detail": "", "seconds": 0})
    payload = {"repo": ROOT.name, "path": str(ROOT), "started": started,
               "finished": datetime.now(timezone.utc).isoformat(timespec="seconds") if finished else None,
               "git": info, "steps": steps}
    try:
        STATE.mkdir(parents=True, exist_ok=True)
        temporary = STATE / (PROGRESS + ".tmp")
        temporary.write_text(json.dumps(payload, indent=2, ensure_ascii=False), encoding="utf-8")
        os.replace(temporary, STATE / PROGRESS)
    except OSError:
        pass


def execute(steps: list, context: Context) -> list:
    results: list = []
    passed: set = set()
    started = datetime.now(timezone.utc).isoformat(timespec="seconds")
    info = head_info()
    context.cache["git-info"] = info
    for step in steps:
        missing = [need for need in step.requirements() if need not in passed]
        if missing:
            detail = f"needs {', '.join(missing)} to pass first"
            results.append(Result(step.group, step.name, step.describe, SKIP, detail))
            print(f"SKIPPED         {step.key}: {detail}", flush=True)
            continue
        print(f"\n== {step.group}: {step.describe}", flush=True)
        write_progress(steps, results, step, started, False, info)
        clock = time.monotonic()
        try:
            detail = step.action(context) or ""
            status = PASS
            passed.add(step.key)
        except StepSkipped as reason:
            detail, status = str(reason), SKIP
        except StepFailed as reason:
            detail, status = str(reason), FAIL
        except Exception as reason:  # a bug in a check must not read as a clean run
            detail, status = f"the check itself failed: {reason!r}", FAIL
        seconds = round(time.monotonic() - clock, 1)
        results.append(Result(step.group, step.name, step.describe, status, detail, seconds))
        head = detail.splitlines()[0] if detail else ""
        print(f"{status.upper():<8} {seconds:6.1f} s  {head}", flush=True)
    write_progress(steps, results, None, started, True, info)
    return results


def summary(results: list, seconds: float) -> str:
    counts = {status: sum(1 for item in results if item.status == status) for status in (PASS, FAIL, SKIP)}
    lines = [f"\n{ROOT.name}: {counts[PASS]} passed, {counts[SKIP]} skipped, "
             f"{counts[FAIL]} failed in {seconds:.0f} s"]
    for item in results:
        head = item.detail.splitlines()[0] if item.detail else ""
        lines.append(f"  {item.status.upper():<8}{item.group:<12}{item.describe:<58}"
                     f"{item.seconds:7.1f} s  {head[:110]}")
    failed = [item for item in results if item.status == FAIL]
    if failed:
        lines.append("\nWhat failed, in full:")
        for item in failed:
            lines.append(f"\n  {item.group}/{item.name} - {item.describe}")
            lines.extend(f"    {line}" for line in item.detail.splitlines())
    return "\n".join(lines)


def main(argv: list | None = None) -> int:
    for stream in (sys.stdout, sys.stderr):
        try:
            stream.reconfigure(encoding="utf-8", errors="replace")
        except (AttributeError, ValueError):
            pass
    parser = argparse.ArgumentParser(description=f"Run every check for {ROOT.name} on this computer.")
    parser.add_argument("--only", help="comma-separated groups: " + ", ".join(GROUPS))
    parser.add_argument("--all", action="store_true", help="include the extra group, which GitHub does not run")
    parser.add_argument("--list", action="store_true", help="show the steps without running them")
    parser.add_argument("--record", action="store_true", help="accept today's findings into the ratchet baseline")
    parser.add_argument("--keep-services", action="store_true", help="leave containers and servers running")
    arguments = parser.parse_args(argv)

    if arguments.only:
        groups = {name.strip() for name in arguments.only.split(",") if name.strip()}
        unknown = groups - set(GROUPS)
        if unknown:
            parser.error("unknown groups: " + ", ".join(sorted(unknown)))
    elif arguments.all or arguments.record:
        groups = set(GROUPS)
    else:
        groups = set(DEFAULT_GROUPS)

    steps = plan(groups)
    if arguments.list:
        for step in steps:
            print(f"{step.group:<12} {step.describe}")
        return 0

    context = build_context(record=arguments.record)
    print(f"{ROOT.name}: {', '.join(group for group in GROUPS if group in groups)}", flush=True)
    if context.dropped:
        print("Withheld from every step (names only): " + ", ".join(context.dropped), flush=True)

    clock = time.monotonic()
    try:
        results = execute(steps, context)
    finally:
        if arguments.keep_services:
            print("Containers and servers are left running (--keep-services).")
        else:
            tear_down(context)
    seconds = time.monotonic() - clock

    report = {"repo": ROOT.name, "groups": [group for group in GROUPS if group in groups],
              "seconds": round(seconds, 1), "git": context.cache.get("git-info", {}),
              "results": [vars(item) for item in results]}
    (STATE / REPORT).write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    print(summary(results, seconds))
    print(f"Report: {STATE / REPORT}")
    return 1 if any(item.status == FAIL for item in results) else 0


# ============================================================ dolibarr-mahnwesen

def in_php(context: Context, version: str, script: str, *, image: str | None = None,
           timeout: int = 1800) -> subprocess.CompletedProcess:
    """Run a bash script in a PHP image, against the Linux-style snapshot."""
    return context.run(docker(context), "run", "--rm", "--mount",
                       f"type=bind,source={SNAPSHOT},target=/work", image or f"php:{version}-cli",
                       "bash", "-c", f"cd /work && {script}", check=False, timeout=timeout)


def take_snapshot(context: Context) -> str:
    """What Git would commit, with the LF endings of a Linux checkout."""
    count = snapshot(context, SNAPSHOT, linux=True)
    if not (SNAPSHOT / "scripts" / "check-module.sh").is_file():
        raise StepFailed("the snapshot has no scripts/check-module.sh")
    return f"{count} files"


def snapshot_step() -> Step:
    return Step("repository", "snapshot", "A Linux-style copy of what Git would commit", take_snapshot)


# ---------------------------------------------------------------- repository

def repository_steps() -> list:
    return [
        Step("repository", "shell", "Every shell script parses", shell_scripts),
        Step("repository", "line-endings", "No CRLF stored for text files", line_endings),
        Step("repository", "whitespace", "Whitespace across every tracked line", whitespace),
        Step("repository", "gitleaks-history", "Gitleaks over the history", gitleaks_history),
        Step("repository", "gitleaks-worktree", "Gitleaks over uncommitted and new files",
             gitleaks_worktree),
    ]


# ----------------------------------------------------------------------- php

def php_step(version: str):
    def action(context: Context) -> str:
        completed = in_php(context, version, "php -r 'echo PHP_VERSION, PHP_EOL;' && bash scripts/check-module.sh")
        text = completed.stdout + completed.stderr
        path = context.log(f"check-module-php{version}", text)
        if completed.returncode != 0:
            raise StepFailed(f"scripts/check-module.sh failed on PHP {version}. Full output: {path}\n"
                             + tail(completed, 30))
        missing = [marker for marker in MODULE_MARKERS if marker not in text]
        if missing:
            raise StepFailed(f"the script exited 0 without reporting: {', '.join(missing)}")
        return f"PHP {text.strip().splitlines()[0]}: lint, policy tests, language keys, contracts"
    return action


def php_steps() -> list:
    return [Step("php", f"php-{version}", f"check-module.sh on PHP {version}", php_step(version),
                 ("repository/snapshot",)) for version in PHP_VERSIONS]


# ------------------------------------------------------------------ dolibarr

def dolibarr_step(version: str):
    def action(context: Context) -> str:
        completed = in_php(context, RELEASE_PHP, f"bash scripts/check-dolibarr-api.sh {version}", timeout=900)
        text = completed.stdout + completed.stderr
        path = context.log(f"dolibarr-api-{version}", text)
        marker = f"Dolibarr {version} source/API compatibility check: OK"
        if completed.returncode != 0 or marker not in text:
            raise StepFailed(f"the Dolibarr {version} compatibility check failed. Full output: {path}\n"
                             + tail(completed, 20))
        return "every API the module relies on is present"
    return action


def dolibarr_steps() -> list:
    return [Step("dolibarr", f"dolibarr-{version}", f"Source and API compatibility with Dolibarr {version}",
                 dolibarr_step(version), ("repository/snapshot",)) for version in DOLIBARR_VERSIONS]


# ------------------------------------------------------------------- package

def scripts_module(name: str):
    """A helper script of scripts/, imported from the working copy."""
    folder = str(ROOT / "scripts")
    if folder not in sys.path:
        sys.path.insert(0, folder)
    return __import__(name)


def package(context: Context) -> str:
    """The installable ZIP, built two ways, byte for byte identical and complete.

    The first build runs in the Git working copy with its output inside the
    repository, as ci.yml does on GitHub; only tracked files may reach it. The
    second runs from the snapshot, which has no .git, as release.py builds from
    git archive. Both must be the same package.
    """
    builder = scripts_module("build_release")
    inside = ROOT / "dist-local-check"
    for folder in (PACKAGE_OUT, inside):
        if folder.exists():
            shutil.rmtree(folder)
    try:
        try:
            working, digest = builder.build(ROOT, inside)
            archive, again = builder.build(SNAPSHOT, PACKAGE_OUT / "snapshot")
        except builder.BuildError as error:
            raise StepFailed(str(error)) from error
        if digest != again or working.read_bytes() != archive.read_bytes():
            raise StepFailed(f"the working copy and the snapshot give different packages: {digest} / {again}")
        problems = builder.verify(working, ROOT) + builder.verify(archive, SNAPSHOT)
    finally:
        if inside.exists():
            shutil.rmtree(inside)
    if problems:
        raise StepFailed("the ZIP is not what Dolibarr should install:\n  " + "\n  ".join(problems))
    with zipfile.ZipFile(archive) as bundle:
        files = sum(1 for info in bundle.infolist() if not info.is_dir())
    context.cache["package"] = archive
    previous = previous_package(context, builder)
    return f"{archive.name}: {files} files, identical from working copy and snapshot, sha256 {digest[:16]}{previous}"


def previous_package(context: Context, builder) -> str:
    """The package of the newest earlier release, built from its tag, for the upgrade test."""
    import io
    import tarfile
    releaser = scripts_module("release")
    current = builder.module_version(SNAPSHOT)
    earlier = [version for version in releaser.released_versions()
               if releaser.version_key(version) < releaser.version_key(current)]
    if not earlier:
        context.cache.pop("previous_package", None)
        return "; no earlier release to upgrade from"
    version = earlier[0]
    completed = subprocess.run([git(context), "archive", "--format=tar", f"v{version}"], cwd=str(ROOT),
                               capture_output=True, timeout=300)
    if completed.returncode != 0:
        raise StepFailed(f"git archive v{version} failed: {completed.stderr.decode('utf-8', 'replace')}")
    source = STATE / "previous-source"
    if source.exists():
        shutil.rmtree(source)
    source.mkdir(parents=True)
    with tarfile.open(fileobj=io.BytesIO(completed.stdout)) as bundle:
        if hasattr(tarfile, "data_filter"):
            bundle.extractall(source, filter="data")
        else:
            bundle.extractall(source)
    zip_path, _ = builder.build(source, PACKAGE_OUT / "previous")
    context.cache["previous_package"] = zip_path
    return f"; upgrade test from v{version}"


def package_steps() -> list:
    return [Step("package", "zip", "Reproducible installable ZIP, checked file by file", package,
                 ("repository/snapshot",))]


# ------------------------------------------------------------------- release

def release_metadata(context: Context) -> str:
    releaser = scripts_module("release")
    try:
        info = releaser.metadata()
    except releaser.ReleaseRefused as error:
        raise StepFailed(str(error)) from error
    tags = context.run(git(context), "tag", "--points-at", "HEAD", "--list", "v*").stdout.split()
    wrong = [tag for tag in tags if tag != info["tag"]]
    if wrong:
        raise StepFailed(f"HEAD is tagged {', '.join(wrong)}, but the module version gives {info['tag']}")
    try:
        progress = releaser.version_progress(info["version"])
    except releaser.ReleaseRefused as error:
        raise StepFailed(str(error)) from error
    kind = "pre-release" if info["prerelease"] else "release"
    return (f"{info['tag']} ({kind}), changelog of {info['date']}, {progress}, "
            f"PHP >= {PHP_VERSIONS[0]}, Dolibarr >= {DOLIBARR_VERSIONS[0]}")


def release_steps() -> list:
    return [Step("release", "metadata", "Version, changelog and support matrix agree", release_metadata)]


# ------------------------------------------------------------------- runtime

def runtime_module():
    """tests/runtime/scenarios.py, imported from the working copy."""
    folder = str(RUNTIME_TESTS)
    if folder not in sys.path:
        sys.path.insert(0, folder)
    import scenarios  # noqa: E402 - lives next to the PHP fixtures it drives
    return scenarios


def runtime_name(version: str, part: str) -> str:
    return f"mahnwesen-rt-{version.replace('.', '')}-{part}"


def start_runtime_stack(context: Context, version: str):
    """One Dolibarr with MariaDB and Mailpit, nothing of the module inside yet, base fixtures loaded.

    The module arrives the way a user installs it: the package is uploaded
    through "Deploy an external module" by the first scenarios. Databases and
    documents live in tmpfs, so every run starts from an empty Dolibarr and
    nothing is left behind. Passwords are new for every run and never written
    to a log.
    """
    import secrets
    scenarios = runtime_module()
    binary = docker(context)
    image, web_port, mail_port = RUNTIME_IMAGES[version]
    if image in RUNTIME_BUILDS and context.run(binary, "image", "inspect", image, check=False,
                                               timeout=60).returncode != 0:
        built = context.run(binary, "build", "--tag", image, RUNTIME_BUILDS[image], check=False, timeout=2400)
        context.log(f"runtime-{version}-image", built.stdout + built.stderr)
        if built.returncode != 0:
            raise StepFailed(f"building {image} failed:\n" + tail(built))
    network = runtime_name(version, "net")
    names = {part: runtime_name(version, part) for part in ("db", "mail", "web")}
    for name in names.values():
        context.run(binary, "rm", "--force", "--volumes", name, check=False, timeout=120)
    context.run(binary, "network", "rm", network, check=False, timeout=60)
    for port in (web_port, mail_port):
        if port_open(port):
            raise StepSkipped(f"port {port} is taken by something else; stop it and run again")
    stack = scenarios.Stack(
        version=version, image=image, web=names["web"], db=names["db"], mail=names["mail"],
        web_port=web_port, mail_port=mail_port, admin_password=secrets.token_urlsafe(18),
        sales_password=secrets.token_urlsafe(18), other_password=secrets.token_urlsafe(18),
        db_password=secrets.token_urlsafe(18), cron_key=secrets.token_hex(16),
        run=context.run, docker=binary, package=context.cache["package"],
        previous_package=context.cache.get("previous_package"))
    context.cache.setdefault("runtime-networks", []).append(network)
    context.run(binary, "network", "create", network, timeout=60)
    # The images declare volumes; these containers are removed with theirs.
    context.cache.setdefault("runtime-containers", []).extend([names["db"], names["mail"], names["web"]])
    context.run(binary, "run", "--detach", "--name", names["db"], "--network", network,
                "--network-alias", "db", "--tmpfs", "/var/lib/mysql",
                "--env", f"MARIADB_ROOT_PASSWORD={stack.db_password}", "--env", "MARIADB_DATABASE=dolibarr",
                "--env", "MARIADB_USER=dolibarr", "--env", f"MARIADB_PASSWORD={stack.db_password}",
                MARIADB_IMAGE, timeout=900)
    context.run(binary, "run", "--detach", "--name", names["mail"], "--network", network,
                "--network-alias", "mail", "--publish", f"127.0.0.1:{mail_port}:8025", MAILPIT_IMAGE,
                timeout=900)
    context.run(binary, "run", "--detach", "--name", names["web"], "--network", network,
                "--publish", f"127.0.0.1:{web_port}:80", "--tmpfs", "/var/www/documents",
                # The image ships custom/ read-only. An administrator who deploys
                # modules through the web makes it writable for the web server.
                "--tmpfs", "/var/www/html/custom:rw,uid=33,gid=33,mode=0755",
                "--mount", f"type=bind,source={SNAPSHOT / 'tests' / 'runtime'},target={scenarios.TESTS_DIR},readonly",
                "--mount", f"type=bind,source={SNAPSHOT / 'tests' / 'runtime' / 'php-check.ini'},"
                           "target=/usr/local/etc/php/conf.d/zz-mahnwesen-check.ini,readonly",
                "--env", "DOLI_DB_HOST=db", "--env", "DOLI_DB_NAME=dolibarr", "--env", "DOLI_DB_USER=dolibarr",
                "--env", f"DOLI_DB_PASSWORD={stack.db_password}", "--env", "DOLI_ADMIN_LOGIN=admin",
                "--env", f"DOLI_ADMIN_PASSWORD={stack.admin_password}", "--env", "DOLI_INSTALL_AUTO=1",
                "--env", f"DOLI_URL_ROOT=http://127.0.0.1:{web_port}", "--env", "DOLI_COMPANY_COUNTRYCODE=AT",
                "--env", "DOLI_COMPANY_NAME=Runtime Verein", "--env", "DOLI_PROD=0",
                "--env", "PHP_INI_DATE_TIMEZONE=Europe/Vienna", image, timeout=900)
    scenarios.wait_http(f"{stack.url}/index.php", 420)
    try:
        stack.fixtures = stack.php_fixture("base")
    except scenarios.CheckFailed as reason:
        raise StepFailed(f"Dolibarr {version}: {reason}") from reason
    finally:
        context.log(f"runtime-{version}-fixtures", "\n".join(output for _, output in stack.notes.get("fixtures", [])))
    # Throwaway accounts of throwaway containers, for --keep-services. The
    # folder is ignored by Git.
    access = STATE / f"runtime-{version}-access.json"
    access.write_text(json.dumps({"url": stack.url, "mailpit": f"http://127.0.0.1:{mail_port}",
                                  "admin": stack.admin_password, "rtsales": stack.sales_password,
                                  "rtother": stack.other_password}, indent=2), encoding="utf-8")
    return stack


def runtime_stacks(context: Context) -> dict:
    """Start every version at once; each version's first step waits for its own."""
    if "runtime-threads" in context.cache:
        return context.cache["runtime-threads"]
    import threading
    runtime_module()
    docker(context)
    started: dict = {}

    def launch(version: str) -> None:
        try:
            started[version] = ("ok", start_runtime_stack(context, version))
        except StepSkipped as reason:
            started[version] = ("skip", str(reason))
        except Exception as reason:  # reported by the version's install step
            started[version] = ("fail", str(reason))

    threads = {version: threading.Thread(target=launch, args=(version,), daemon=True)
               for version in DOLIBARR_VERSIONS}
    for thread in threads.values():
        thread.start()
    context.cache["runtime-threads"] = (threads, started)
    return context.cache["runtime-threads"]


def runtime_step(version: str, name: str, action):
    def run(context: Context) -> str:
        scenarios = runtime_module()
        threads, started = runtime_stacks(context)
        # The first run may build an image; later runs start in under a minute.
        threads[version].join(timeout=2700)
        state, value = started.get(version, ("fail", "the stack did not start within 45 minutes"))
        if state == "skip":
            raise StepSkipped(value)
        if state == "fail":
            raise StepFailed(f"Dolibarr {version} did not start: {value}")
        try:
            return action(value)
        except scenarios.CheckFailed as reason:
            raise StepFailed(f"{reason}\nLogs: docker logs {value.web} (use --keep-services to inspect)") from reason
    return run


def runtime_php_messages(version: str):
    def run(context: Context) -> str:
        threads, started = runtime_stacks(context)
        state, stack = started.get(version, ("fail", None))
        if state != "ok":
            raise StepSkipped(f"Dolibarr {version} did not start")
        found = runtime_module().php_messages(stack)
        context.log(f"runtime-{version}-web", stack.log())
        return ratchet(context, f"runtime-php-{version}", found,
                       f"PHP errors, warnings and deprecations from module code on Dolibarr {version}")
    return run


def runtime_steps() -> list:
    scenarios = runtime_module()
    steps = []
    for version in DOLIBARR_VERSIONS:
        group = "runtime"
        prefix = f"{version}-"
        for name, describe, action, needs in scenarios.SCENARIOS:
            requirements = tuple(f"{prefix}{need}" for need in needs) or ("package/zip",)
            steps.append(Step(group, f"{prefix}{name}", f"Dolibarr {version}: {describe}",
                              runtime_step(version, name, action), requirements))
        steps.append(Step(group, f"{prefix}php-messages",
                          f"Dolibarr {version}: no PHP messages from module code",
                          runtime_php_messages(version), (f"{prefix}upgrade",)))
    return steps


def remove_runtime_stacks(context: Context) -> None:
    binary = shutil.which("docker", path=context.env.get("PATH"))
    if not binary:
        return
    for name in context.cache.pop("runtime-containers", []):
        subprocess.run([binary, "rm", "--force", "--volumes", name], capture_output=True, text=True, timeout=180)
    for network in context.cache.pop("runtime-networks", []):
        subprocess.run([binary, "network", "rm", network], capture_output=True, text=True, timeout=120)
    for access in STATE.glob("runtime-*-access.json"):
        access.unlink(missing_ok=True)


# --------------------------------------------------------------------- extra

def php_deprecations(context: Context) -> str:
    """Deprecations and warnings on the newest PHP.

    php -l reports parse errors only. A deprecation turns into an error in the
    next major PHP, and the policy tests are where it would show first.
    """
    newest = PHP_VERSIONS[-1]
    completed = in_php(context, newest,
                       "php -d error_reporting=-1 -d display_errors=stderr -d log_errors=0 tests/run.php")
    text = completed.stdout + completed.stderr
    context.log("php-deprecations", text)
    found = set()
    for line in text.splitlines():
        match = re.search(r"(Deprecated|Warning|Notice):\s*(.+?) in /work/(\S+) on line \d+", line)
        if match:
            found.add(f"{match.group(3)}: {match.group(1)}: {match.group(2)}")
    if completed.returncode != 0 and not found:
        raise StepFailed(f"tests/run.php failed on PHP {newest}:\n" + tail(completed))
    return ratchet(context, "php-deprecations", found, f"deprecations and warnings on PHP {newest}")


PLACEHOLDER = re.compile(r"(?<!%)%(?:\d+\$)?[-+0#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]")


def lang_placeholders(context: Context) -> str:
    """The German and English texts carry the same placeholders.

    A key whose translation drops a %s prints the wrong value, or none, and only
    in one language. check-module.sh compares the keys, not what is inside them.
    """
    def parse(path: Path) -> dict:
        entries = {}
        for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
            if line.strip() and not line.lstrip().startswith("#") and "=" in line:
                key, value = line.split("=", 1)
                entries[key.strip()] = value
        return entries

    german = parse(ROOT / "langs" / "de_DE" / "mahnwesen.lang")
    english = parse(ROOT / "langs" / "en_US" / "mahnwesen.lang")
    found = set()
    for key in sorted(german.keys() & english.keys()):
        left = sorted(PLACEHOLDER.findall(german[key]))
        right = sorted(PLACEHOLDER.findall(english[key]))
        if left != right:
            found.add(f"{key}: de {left} / en {right}")
    return ratchet(context, "lang-placeholders", found, "texts whose placeholders differ between languages")


def extra_steps() -> list:
    return [
        Step("extra", "deprecations", f"Deprecations on PHP {PHP_VERSIONS[-1]}", php_deprecations,
             ("repository/snapshot",)),
        Step("extra", "placeholders", "German and English texts carry the same placeholders",
             lang_placeholders),
        Step("extra", "shellcheck", "ShellCheck over the scripts", shellcheck),
        Step("extra", "osv", "Known vulnerabilities in the lockfiles", osv_scan),
    ]


# -------------------------------------------------------------------- wiring

def plan(groups: set) -> list:
    builders = {"repository": repository_steps, "php": php_steps, "dolibarr": dolibarr_steps,
                "package": package_steps, "release": release_steps, "runtime": runtime_steps,
                "extra": extra_steps}
    steps: list = []
    if groups & {"php", "dolibarr", "package", "runtime", "extra"}:
        steps.append(snapshot_step())
    for group in GROUPS:
        if group in groups:
            steps += builders[group]()
    return steps


def build_context(record: bool = False) -> Context:
    return base_context(record)


def tear_down(context: Context) -> None:
    stop_everything(context)
    remove_runtime_stacks(context)


if __name__ == "__main__":
    sys.exit(main())
