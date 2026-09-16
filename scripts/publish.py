#!/usr/bin/env python3
"""Build and publish the installable module ZIP.

    python scripts/publish.py dev        development package of main, after a merge
    python scripts/publish.py release    release vX.Y.Z of the module version
    options: --ci        inside GitHub Actions: build on the runner, no local report needed
             --dry-run   build and verify, change nothing on GitHub

Development package: mahnwesen-<version>.<n>.zip, where n counts the commits
since tag v<version>, and the module descriptor inside says the same version.
It lives in one rolling pre-release, "Entwicklungsstand (main)" (tag dev-main),
that always holds the newest main. Dolibarr's installer takes the module folder
from the file name, which is why the name carries digits and not "main".

Local first: the package is built from `git archive` of the exact commit, in
the Docker image the local check builds its ZIP with, and only after
scripts/local_check.py passed for that commit - the script runs it when needed.
GitHub builds again after its checks on main (ci.yml) and for release tags
(release.yml). Whoever publishes first uploads; whoever comes second downloads
the published ZIP and compares it file by file, and fails on any difference.
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import os
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DESCRIPTOR = ROOT / "core" / "modules" / "modMahnwesen.class.php"
DEV_TAG = "dev-main"
DEV_TITLE = "Entwicklungsstand (main)"
MODULE = "mahnwesen"
# admin/modules.php of Dolibarr 21 to 24: the accepted file names, and the
# module folder the installer then looks for inside the archive.
INSTALLER_NAME = re.compile(r"^(module[a-zA-Z0-9]*_|theme_|).*\-([0-9][0-9\.]*)(\s\(\d+\)\s)?\.zip$", re.IGNORECASE)


class PublishError(Exception):
    """Publishing stopped. The message says why and what to do."""


def installer_module_name(filename: str) -> str | None:
    """The module folder Dolibarr's installer expects for this file name, or None."""
    if not INSTALLER_NAME.match(filename):
        return None
    name = re.sub(r"module_", "", filename, count=1)
    return re.sub(r"\-([0-9][0-9\.]*)\.zip$", "", name, flags=re.IGNORECASE)


def run(*arguments, cwd: Path = ROOT, check: bool = True, capture: bool = True,
        env: dict | None = None, stdin: bytes | None = None) -> subprocess.CompletedProcess:
    merged = dict(os.environ)
    if env:
        merged.update(env)
    completed = subprocess.run([str(part) for part in arguments], cwd=str(cwd), env=merged, input=stdin,
                               capture_output=capture)
    if check and completed.returncode != 0:
        output = (completed.stdout or b"").decode("utf-8", "replace") + (completed.stderr or b"").decode("utf-8", "replace")
        raise PublishError(f"{' '.join(str(part) for part in arguments[:4])} failed:\n{output.strip()[-2000:]}")
    return completed


def git(*arguments, check: bool = True) -> str:
    return run("git", *arguments, check=check).stdout.decode("utf-8", "replace").strip()


def module_version() -> str:
    match = re.search(r"\$this->version\s*=\s*'([^']+)'", DESCRIPTOR.read_text(encoding="utf-8"))
    version = match.group(1) if match else ""
    if not re.fullmatch(r"\d+\.\d+\.\d+", version):
        raise PublishError(f"the module version {version!r} is not a release version x.y.z")
    return version


def changelog_section(version: str) -> str:
    text = (ROOT / "CHANGELOG.md").read_text(encoding="utf-8")
    match = re.search(rf"^## {re.escape(version)}\s*$(.*?)(?=^## |\Z)", text, re.MULTILINE | re.DOTALL)
    return match.group(1).strip() if match else ""


def remote_ref(ref: str) -> str:
    """The commit a remote branch or tag points at; annotated tags are peeled."""
    lines = git("ls-remote", "origin", ref, ref + "^{}", check=False).splitlines()
    peeled = [line.split()[0] for line in lines if line.endswith("^{}")]
    plain = [line.split()[0] for line in lines if line.strip() and not line.endswith("^{}")]
    return (peeled or plain or [""])[0]


# ---------------------------------------------------------------- local check

def local_check_passed(commit: str) -> str:
    """Why the local check does not count for this commit, or '' when it does."""
    sys.path.insert(0, str(ROOT / "scripts"))
    import local_check  # noqa: E402 - the local check lives next to this script
    report_path = local_check.STATE / local_check.REPORT
    try:
        report = json.loads(report_path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return "no local check report yet"
    info = report.get("git") or {}
    if not commit.startswith(str(info.get("head") or "-")):
        return f"the last local check ran on {info.get('head')}, not on {commit[:7]}"
    if info.get("dirty"):
        return "the last local check ran on uncommitted changes"
    missing = [group for group in local_check.DEFAULT_GROUPS if group not in report.get("groups", [])]
    if missing:
        return f"the last local check left out {', '.join(missing)}"
    bad = [f"{item['group']}/{item['name']}" for item in report.get("results", []) if item.get("status") != "passed"]
    if bad:
        return f"the last local check did not pass: {', '.join(bad[:5])}"
    return ""


def ensure_local_check(commit: str) -> None:
    reason = local_check_passed(commit)
    if not reason:
        print(f"Local check: passed for {commit[:7]}")
        return
    print(f"Local check: {reason}. Running it now.", flush=True)
    completed = subprocess.run([sys.executable, str(ROOT / "scripts" / "local_check.py")], cwd=str(ROOT))
    if completed.returncode != 0:
        raise PublishError("the local check failed; nothing was published")
    reason = local_check_passed(commit)
    if reason:
        raise PublishError(f"the local check did not confirm {commit[:7]}: {reason}")


# ---------------------------------------------------------------------- build

def build_locally(commit: str, version: str, workdir: Path) -> Path:
    """git archive of the commit, built in the local check's ZIP image."""
    sys.path.insert(0, str(ROOT / "scripts"))
    import local_check  # noqa: E402
    source = workdir / "source"
    if source.exists():
        shutil.rmtree(source)
    source.mkdir(parents=True)
    archive = run("git", "archive", "--format=tar", commit).stdout
    with tarfile.open(fileobj=io.BytesIO(archive)) as bundle:
        try:
            bundle.extractall(source, filter="data")
        except TypeError:  # Python before 3.11.4 has no extraction filters
            bundle.extractall(source)
    context = local_check.base_context()
    try:
        binary = local_check.docker(context)
        local_check.ensure_zip_image(context)
    except (local_check.StepSkipped, local_check.StepFailed) as reason:
        raise PublishError(f"the build image is not available: {reason}") from reason
    epoch = git("log", "-1", "--format=%ct", commit)
    completed = run(binary, "run", "--rm", "--mount", f"type=bind,source={source},target=/work",
                    "--env", f"SOURCE_DATE_EPOCH={epoch}", "--env", f"PACKAGE_VERSION={version}",
                    local_check.ZIP_IMAGE, "bash", "-c", "cd /work && bash scripts/build-release.sh", check=False)
    if completed.returncode != 0:
        raise PublishError("building the package failed:\n" + (completed.stdout + completed.stderr).decode("utf-8", "replace")[-2000:])
    return source / "dist" / f"{MODULE}-{version}.zip"


def build_on_runner(version: str) -> Path:
    run("bash", "scripts/build-release.sh", env={"PACKAGE_VERSION": version})
    return ROOT / "dist" / f"{MODULE}-{version}.zip"


def fingerprint(archive: Path) -> dict:
    """Every file of the package with the SHA-256 of its content."""
    with zipfile.ZipFile(archive) as bundle:
        return {info.filename: hashlib.sha256(bundle.read(info)).hexdigest()
                for info in bundle.infolist() if not info.is_dir()}


def verify_package(archive: Path, version: str, commit: str) -> dict:
    problems = []
    if not archive.is_file():
        raise PublishError(f"the build produced no {archive.name}")
    if installer_module_name(archive.name) != MODULE:
        problems.append(f"Dolibarr's installer would not accept {archive.name} as module {MODULE}")
    files = fingerprint(archive)
    outside = sorted(name for name in files if not name.startswith(f"{MODULE}/"))
    if outside:
        problems.append(f"entries outside {MODULE}/: {', '.join(outside[:3])}")
    expected = set()
    listed = git("ls-tree", "-r", "--name-only", commit).splitlines()
    excluded = (".git", ".github", "dist", "build", "scripts", "tests", ".gitignore", ".gitattributes",
                ".editorconfig", "CONTRIBUTING.md", "CLAUDE.md")
    for name in listed:
        if not any(name == item or name.startswith(item + "/") for item in excluded):
            expected.add(f"{MODULE}/{name}")
    if set(files) != expected:
        missing = sorted(expected - set(files))
        extra = sorted(set(files) - expected)
        problems.append(f"the package differs from commit {commit[:7]}: missing {missing[:3]}, extra {extra[:3]}")
    with zipfile.ZipFile(archive) as bundle:
        descriptor = bundle.read(f"{MODULE}/core/modules/modMahnwesen.class.php").decode("utf-8", "replace")
    if f"$this->version = '{version}'" not in descriptor:
        problems.append(f"the module descriptor in the package does not say version {version}")
    checksum = archive.with_name(archive.name + ".sha256")
    if not checksum.is_file() or checksum.read_text(encoding="utf-8").split()[0] != hashlib.sha256(archive.read_bytes()).hexdigest():
        problems.append(f"{checksum.name} is missing or does not match")
    if problems:
        raise PublishError("the package is not fit to publish:\n  " + "\n  ".join(problems))
    return files


# --------------------------------------------------------------------- GitHub

def gh(*arguments, check: bool = True) -> subprocess.CompletedProcess:
    return run("gh", *arguments, check=check)


def release_info(tag: str) -> dict | None:
    completed = gh("release", "view", tag, "--json", "tagName,isPrerelease,assets,body,url", check=False)
    if completed.returncode != 0:
        return None
    return json.loads(completed.stdout)


def compare_with_published(tag: str, archive: Path, files: dict, who: str) -> str:
    info = release_info(tag)
    asset = next((item for item in (info or {}).get("assets", []) if item["name"] == archive.name), None)
    if asset is None:
        raise PublishError(f"release {tag} exists but carries no {archive.name}; published assets: "
                           f"{[item['name'] for item in (info or {}).get('assets', [])]}")
    with tempfile.TemporaryDirectory(prefix="mahnwesen-published-") as folder:
        gh("release", "download", tag, "--pattern", archive.name, "--dir", folder)
        published = fingerprint(Path(folder) / archive.name)
    if published != files:
        differing = sorted(name for name in set(published) | set(files) if published.get(name) != files.get(name))
        raise PublishError(f"the published {archive.name} differs from this build in {len(differing)} files: "
                           f"{', '.join(differing[:5])}")
    marker = f"Zweiter, unabhängiger Build ({who}): alle {len(files)} Dateien identisch."
    body = info.get("body") or ""
    if marker not in body:
        with tempfile.NamedTemporaryFile("w", suffix=".md", delete=False, encoding="utf-8") as handle:
            handle.write(body.rstrip() + "\n\n" + marker + "\n")
        gh("release", "edit", tag, "--notes-file", handle.name)
    return f"verified: {info['url']} holds the same {len(files)} files as this build"


def create_release(tag: str, commit: str, archive: Path, notes: str, prerelease: bool, title: str) -> str:
    with tempfile.NamedTemporaryFile("w", suffix=".md", delete=False, encoding="utf-8") as handle:
        handle.write(notes)
    arguments = ["release", "create", tag, str(archive), str(archive) + ".sha256", "--target", commit,
                 "--title", title, "--notes-file", handle.name]
    arguments += ["--prerelease", "--latest=false"] if prerelease else ["--latest"]
    completed = gh(*arguments, check=False)
    if completed.returncode != 0:
        if release_info(tag) is None:
            raise PublishError(f"creating release {tag} failed:\n" + completed.stderr.decode("utf-8", "replace")[-1500:])
        return ""
    return json.loads(gh("release", "view", tag, "--json", "url").stdout)["url"]


# ------------------------------------------------------------------ commands

def publish(kind: str, ci: bool, dry_run: bool) -> str:
    who = "GitHub Actions" if ci else "lokal"
    git("fetch", "--quiet", "--tags", "--force", "origin", check=False)
    commit = git("rev-parse", "HEAD")
    main = remote_ref("refs/heads/main")
    # A release tag in GitHub Actions may point behind main; everything else
    # publishes the newest main only.
    # A dry run builds and verifies any commit, a branch included.
    if commit != main and not (ci and kind == "release") and not dry_run:
        if ci:
            return f"skipped: main moved on to {main[:7]}; the newer run publishes it"
        raise PublishError(f"HEAD {commit[:7]} is not origin/main {main[:7]}. Switch to main and pull first.")
    if not ci and not dry_run and git("status", "--porcelain"):
        raise PublishError("the working copy has uncommitted changes; publish only committed work")

    version = module_version()
    base_tag = f"v{version}"
    subject = git("log", "-1", "--format=%s", commit)
    if kind == "dev":
        tagged = git("rev-list", "-n", "1", base_tag, check=False)
        if not tagged:
            return (f"skipped: version {version} is not released yet (no tag {base_tag}); "
                    "this is a release commit - run: python scripts/publish.py release")
        count = int(git("rev-list", "--count", f"{base_tag}..{commit}"))
        if count == 0:
            return f"skipped: main is exactly release {base_tag}; its package is already published"
        package_version = f"{version}.{count}"
        tag, title, prerelease = DEV_TAG, DEV_TITLE, True
        unreleased = changelog_section("Unreleased") or "-"
        notes = (f"Entwicklungsstand von `main` - nicht für den Produktivbetrieb, vorher auf einem Test-System prüfen.\n\n"
                 f"- Paket: `{MODULE}-{package_version}.zip` = Release {version} plus {count} Commits; "
                 f"in Dolibarr als Version {package_version} sichtbar\n"
                 f"- Commit: {commit} - {subject}\n"
                 f"- Erster Build: {who}\n\n"
                 f"## Seit {version} (CHANGELOG, Unreleased)\n\n{unreleased}\n")
    else:
        section = changelog_section(version)
        if not section:
            raise PublishError(f"CHANGELOG.md has no filled section '## {version}'")
        package_version = version
        tag, title, prerelease = base_tag, f"Mahnwesen {version}", False
        notes = f"{section}\n\n- Commit: {commit}\n- Erster Build: {who}\n"

    if not ci and not dry_run:
        ensure_local_check(commit)
    workdir = ROOT / ".local-testing" / "publish"
    archive = build_on_runner(package_version) if ci else build_locally(commit, package_version, workdir)
    files = verify_package(archive, package_version, commit)
    print(f"Built {archive.name}: {len(files)} files, verified against commit {commit[:7]}")
    if dry_run:
        return f"dry run: {archive} is ready; nothing was changed on GitHub"

    existing = release_info(tag)
    if existing is not None:
        published_commit = remote_ref(f"refs/tags/{tag}")
        if tag == DEV_TAG and published_commit and published_commit != commit:
            newer = run("git", "merge-base", "--is-ancestor", published_commit, commit, check=False).returncode == 0
            if not newer:
                return f"skipped: {tag} already holds {published_commit[:7]}, which is not older than {commit[:7]}"
            gh("release", "delete", tag, "--cleanup-tag", "--yes")
            existing = None
        elif published_commit != commit:
            raise PublishError(f"release {tag} exists for commit {published_commit[:7]}, not {commit[:7]}")
    if existing is not None:
        return compare_with_published(tag, archive, files, who)
    url = create_release(tag, commit, archive, notes, prerelease, title)
    if url:
        return f"published: {url}"
    # Someone published between the check and the upload: compare instead.
    return compare_with_published(tag, archive, files, who)


def main(argv: list | None = None) -> int:
    parser = argparse.ArgumentParser(description="Build and publish the installable module ZIP.")
    parser.add_argument("kind", choices=("dev", "release"))
    parser.add_argument("--ci", action="store_true", help="running inside GitHub Actions")
    parser.add_argument("--dry-run", action="store_true", help="build and verify only")
    arguments = parser.parse_args(argv)
    try:
        print(publish(arguments.kind, arguments.ci, arguments.dry_run))
        return 0
    except PublishError as reason:
        print(f"Not published: {reason}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
