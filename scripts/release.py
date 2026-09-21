#!/usr/bin/env python3
"""Publish a Mahnwesen release: tag, GitHub release with patch notes, package upload.

Runs on the developer's machine after the release pull request is merged. It
refuses unless everything that makes a release trustworthy holds, and GitHub
re-verifies the published package afterwards (.github/workflows/release-verify.yml).

Usage:
    python scripts/release.py --metadata              version, changelog and support matrix agree (no git, no network)
    python scripts/release.py --metadata --tag vX     ... and the tag matches the module version
    python scripts/release.py --check                 every precondition and the package, but no tag and no release
    python scripts/release.py                         publish

See docs/RELEASES.md for the version scheme.
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

import build_release  # noqa: E402 - lives next to this script

REPORT = ROOT / ".local-testing" / "local-check.json"
REPOSITORY = "Tabsi1998/dolibarr-mahnwesen"
# Groups of scripts/local_check.py a release needs to have passed, none skipped.
REQUIRED_GROUPS = ("repository", "php", "dolibarr", "package", "release", "runtime")


class ReleaseRefused(Exception):
    """A precondition does not hold. The message says which and how to fix it."""


# ------------------------------------------------------------------ metadata

def version_parts(version: str) -> tuple[int, int, int, bool]:
    match = build_release.VERSION_RULE.match(version)
    if not match:
        raise ReleaseRefused(f"module version {version!r} is neither x.y.z nor x.y.z-beta")
    return int(match.group(1)), int(match.group(2)), int(match.group(3)), bool(match.group(4))


def changelog_section(version: str, text: str | None = None) -> tuple[str, str]:
    """The dated section of a version: (date, body)."""
    text = (ROOT / "CHANGELOG.md").read_text(encoding="utf-8") if text is None else text
    headings = list(re.finditer(r"^## \[([^\]]+)\](?: - (\d{4}-\d{2}-\d{2}))?[ \t]*$", text, re.MULTILINE))
    if not headings or headings[0].group(1) != "Unreleased":
        raise ReleaseRefused("CHANGELOG.md must start its versions with ## [Unreleased]")
    released = [heading for heading in headings if heading.group(1) != "Unreleased"]
    if not released:
        raise ReleaseRefused(f"CHANGELOG.md has no section ## [{version}] - YYYY-MM-DD")
    newest = released[0]
    if newest.group(1) != version:
        raise ReleaseRefused(f"the newest CHANGELOG.md section is {newest.group(1)}, the module version is {version}")
    if not newest.group(2):
        raise ReleaseRefused(f"the CHANGELOG.md section of {version} has no date (## [{version}] - YYYY-MM-DD)")
    end = released[1].start() if len(released) > 1 else len(text)
    body = text[newest.end():end]
    body = re.split(r"^\[[^\]]+\]: https?://", body, maxsplit=1, flags=re.MULTILINE)[0].strip()
    if not body:
        raise ReleaseRefused(f"the CHANGELOG.md section of {version} is empty")
    try:
        released_on = date.fromisoformat(newest.group(2))
    except ValueError as error:
        raise ReleaseRefused(f"the CHANGELOG.md date {newest.group(2)} is not a date") from error
    if released_on > date.today():
        raise ReleaseRefused(f"the CHANGELOG.md section of {version} is dated in the future ({released_on})")
    if f"[{version}]: https://github.com/{REPOSITORY}/releases/tag/v{version}" not in text:
        raise ReleaseRefused(f"CHANGELOG.md lacks the link [{version}]: https://github.com/{REPOSITORY}/releases/tag/v{version}")
    return newest.group(2), body


def first_list_entry(pattern: str, text: str, what: str) -> str:
    match = re.search(pattern, text, re.MULTILINE | re.DOTALL)
    if not match:
        raise ReleaseRefused(f"cannot find {what}")
    return match.group(1)


def support_matrix() -> list[str]:
    """Every place that names the lowest PHP and Dolibarr version agrees."""
    descriptor = (ROOT / build_release.DESCRIPTOR).read_text(encoding="utf-8")
    php_min = re.search(r"\$this->phpmin\s*=\s*array\((\d+),\s*(\d+)\)", descriptor)
    dolibarr_min = re.search(r"\$this->need_dolibarr_version\s*=\s*array\((\d+),\s*(\d+)\)", descriptor)
    if not php_min or not dolibarr_min:
        return ["the descriptor lacks phpmin or need_dolibarr_version"]
    php = f"{php_min.group(1)}.{php_min.group(2)}"
    dolibarr = f"{dolibarr_min.group(1)}.{dolibarr_min.group(2)}"
    local_check = (ROOT / "scripts" / "local_check.py").read_text(encoding="utf-8")
    api_check = (ROOT / "scripts" / "check-dolibarr-api.sh").read_text(encoding="utf-8")
    ci = (ROOT / ".github" / "workflows" / "ci.yml").read_text(encoding="utf-8")
    found = {
        "scripts/local_check.py PHP_VERSIONS": (first_list_entry(r'^PHP_VERSIONS = \("([^"]+)"', local_check, "PHP_VERSIONS"), php),
        "scripts/local_check.py DOLIBARR_VERSIONS": (first_list_entry(r'^DOLIBARR_VERSIONS = \("([^"]+)"', local_check, "DOLIBARR_VERSIONS"), dolibarr),
        "scripts/check-dolibarr-api.sh versions": (first_list_entry(r'^  ([0-9.]+)\|[0-9.|]*\) ;;', api_check, "the accepted versions"), dolibarr),
        "ci.yml php matrix": (first_list_entry(r"php: \['([^']+)'", ci, "the php matrix in ci.yml"), php),
        "ci.yml dolibarr matrix": (first_list_entry(r"dolibarr: \['([^']+)'", ci, "the dolibarr matrix in ci.yml"), dolibarr),
    }
    for readme in ("README.md", "README-DE.md"):
        text = (ROOT / readme).read_text(encoding="utf-8")
        found[f"{readme} Dolibarr minimum"] = (first_list_entry(r"^\| Dolibarr \| ([0-9.]+) \|", text, f"the Dolibarr row in {readme}"), dolibarr)
        found[f"{readme} PHP minimum"] = (first_list_entry(r"^\| PHP \| ([0-9.]+) \|", text, f"the PHP row in {readme}"), php)
    return [f"{where} starts at {actual}, the descriptor says {expected}"
            for where, (actual, expected) in found.items() if actual != expected]


def version_key(version: str) -> tuple[int, int, int, int]:
    """Order of versions: 0.1.0-beta < 0.1.0 < 0.1.1-beta."""
    major, minor, patch, beta = version_parts(version)
    return major, minor, patch, 0 if beta else 1


def released_versions() -> list[str]:
    """Versions that have a tag, newest first. Empty without git or tags (a shallow CI checkout)."""
    if not shutil.which("git"):
        return []
    completed = run("git", "tag", "--list", "v*", check=False)
    versions = []
    for tag in completed.stdout.split():
        if build_release.VERSION_RULE.match(tag[1:]):
            versions.append(tag[1:])
    return sorted(versions, key=version_key, reverse=True)


def version_progress(version: str) -> str:
    """A change to the installable package needs a new version, higher than every release.

    Every merged pull request that changes the package is published, so a pull
    request that changes it without raising the version would collide with the
    release that is already out.
    """
    released = released_versions()
    if not released:
        return "no release yet"
    newest = released[0]
    if version_key(version) < version_key(newest):
        raise ReleaseRefused(f"version {version} is lower than the released {newest}")
    if version != newest:
        return f"{version} follows the released {newest}"
    changed = run("git", "diff", "--name-only", f"v{newest}", check=False).stdout.split()
    changed += run("git", "ls-files", "--others", "--exclude-standard", check=False).stdout.split()
    in_package = sorted({name for name in changed if build_release.included(Path(name))})
    if in_package:
        shown = ", ".join(in_package[:5]) + (" ..." if len(in_package) > 5 else "")
        raise ReleaseRefused(f"the package changed since the release {newest} ({shown}), but the version is still "
                             f"{version}. Raise $this->version and add a CHANGELOG.md section for the new version.")
    return f"{version} is released and the package is unchanged since"


def metadata(tag: str | None = None) -> dict:
    version = build_release.module_version(ROOT)
    major, _, _, beta = version_parts(version)
    problems = []
    if major == 0 and not beta:
        problems.append(f"version {version} is below 1.0.0 and must be a beta ({version}-beta)")
    try:
        released_on, notes = changelog_section(version)
    except ReleaseRefused as error:
        problems.append(str(error))
        released_on, notes = "", ""
    problems += support_matrix()
    if tag is not None and tag != f"v{version}":
        problems.append(f"tag {tag} does not match the module version {version} (expected v{version})")
    if problems:
        raise ReleaseRefused("the release metadata disagrees:\n  " + "\n  ".join(problems))
    return {"version": version, "tag": f"v{version}", "prerelease": beta, "date": released_on, "notes": notes,
            "title": f"Mahnwesen v{version}", "package": build_release.package_name(version)}


# ----------------------------------------------------------------- git state

def run(*command: str, check: bool = True, cwd: Path = ROOT) -> subprocess.CompletedProcess:
    completed = subprocess.run(list(command), cwd=str(cwd), capture_output=True, text=True,
                               encoding="utf-8", errors="replace")
    if check and completed.returncode != 0:
        raise ReleaseRefused(f"{' '.join(command)} failed:\n{(completed.stdout + completed.stderr).strip()}")
    return completed


def require_tool(name: str, hint: str) -> None:
    if not shutil.which(name):
        raise ReleaseRefused(f"{name} is not installed. {hint}")


def git_state(tag: str) -> str:
    require_tool("git", "Install Git for Windows.")
    require_tool("gh", "winget install GitHub.cli, then gh auth login.")
    branch = run("git", "branch", "--show-current").stdout.strip()
    if branch != "main":
        raise ReleaseRefused(f"releases are made from main, this is {branch or 'a detached HEAD'}")
    if run("git", "status", "--porcelain").stdout.strip():
        raise ReleaseRefused("the working copy has uncommitted or untracked changes")
    run("git", "fetch", "--prune", "--tags", "origin")
    head = run("git", "rev-parse", "HEAD").stdout.strip()
    remote = run("git", "rev-parse", "origin/main").stdout.strip()
    if head != remote:
        raise ReleaseRefused(f"main ({head[:7]}) is not origin/main ({remote[:7]}); pull or push first")
    tag_free(tag)
    return head


def tag_free(tag: str) -> None:
    if run("git", "tag", "--list", tag).stdout.strip():
        raise ReleaseRefused(f"the tag {tag} exists already")
    if run("git", "ls-remote", "--tags", "origin", f"refs/tags/{tag}").stdout.strip():
        raise ReleaseRefused(f"the tag {tag} exists on GitHub already")
    if run("gh", "release", "view", tag, "--repo", REPOSITORY, check=False).returncode == 0:
        raise ReleaseRefused(f"a GitHub release {tag} exists already")


def same_content(first: str, second: str) -> bool:
    """Whether two commits hold the same files, as a merge commit and the branch commit it merged."""
    trees = [run("git", "rev-parse", "--verify", "--quiet", f"{commit}^{{tree}}", check=False).stdout.strip()
             for commit in (first, second)]
    return bool(trees[0]) and trees[0] == trees[1]


def local_check_passed(head: str) -> str:
    try:
        report = json.loads(REPORT.read_text(encoding="utf-8"))
    except (OSError, ValueError) as error:
        raise ReleaseRefused(f"no readable {REPORT.relative_to(ROOT)}; run python scripts/local_check.py") from error
    git = report.get("git") or {}
    checked = str(git.get("head") or "")
    if not checked or not (head.startswith(checked) or same_content(head, checked)):
        raise ReleaseRefused(f"the last local check ran on {checked or '-'}, not on {head[:7]} or a commit with the "
                             "same content; run it again")
    if git.get("dirty"):
        raise ReleaseRefused("the last local check ran on a working copy with changes; run it again on the clean commit")
    missing = [group for group in REQUIRED_GROUPS if group not in (report.get("groups") or [])]
    if missing:
        raise ReleaseRefused(f"the last local check did not include {', '.join(missing)}")
    results = report.get("results") or []
    failed = [f"{item['group']}/{item['name']}" for item in results if item.get("status") == "failed"]
    skipped = [f"{item['group']}/{item['name']}: {item.get('detail', '')[:80]}" for item in results
               if item.get("status") == "skipped" and item.get("group") in REQUIRED_GROUPS]
    if failed or skipped:
        raise ReleaseRefused("the last local check is not green:\n  " + "\n  ".join(failed + skipped))
    passed = sum(1 for item in results if item.get("status") == "passed")
    return f"{passed} steps passed on {head[:7]}"


def package_from_commit(head: str, out: Path) -> tuple[Path, str]:
    """Build from exactly what is committed, never from the working copy."""
    archive = run_bytes("git", "archive", "--format=tar", head)
    source = out / "source"
    source.mkdir(parents=True)
    with tarfile.open(fileobj=io.BytesIO(archive)) as bundle:
        for member in bundle.getmembers():
            if member.name.startswith("/") or ".." in Path(member.name).parts:
                raise ReleaseRefused(f"git archive produced an unsafe path: {member.name}")
        if hasattr(tarfile, "data_filter"):
            bundle.extractall(source, filter="data")
        else:
            bundle.extractall(source)  # Python before 3.11.4; the paths were checked above
    zip_path, digest = build_release.build(source, out / "dist")
    problems = build_release.verify(zip_path, source)
    if problems:
        raise ReleaseRefused("the package is not what Dolibarr should install:\n  " + "\n  ".join(problems))
    return zip_path, digest


def run_bytes(*command: str) -> bytes:
    completed = subprocess.run(list(command), cwd=str(ROOT), capture_output=True)
    if completed.returncode != 0:
        raise ReleaseRefused(f"{' '.join(command)} failed: {completed.stderr.decode('utf-8', 'replace').strip()}")
    return completed.stdout


# ------------------------------------------------------------------- publish

def publish(info: dict, zip_path: Path, digest: str, work: Path, commit: str = "HEAD") -> None:
    tag = info["tag"]
    notes = work / "notes.md"
    notes.write_text(info["notes"] + "\n", encoding="utf-8", newline="\n")
    checksum = zip_path.with_name(zip_path.name + ".sha256")
    run("git", "tag", "--annotate", tag, commit, "--message", info["title"])
    try:
        run("git", "push", "origin", f"refs/tags/{tag}")
    except ReleaseRefused:
        run("git", "tag", "--delete", tag, check=False)
        raise
    command = ["gh", "release", "create", tag, str(zip_path), str(checksum), "--repo", REPOSITORY,
               "--title", info["title"], "--notes-file", str(notes), "--verify-tag"]
    command += ["--prerelease"] if info["prerelease"] else ["--latest"]
    try:
        run(*command)
    except ReleaseRefused as error:
        raise ReleaseRefused(f"{error}\nThe tag {tag} is pushed but the release was not created. Either run "
                             f"python scripts/release.py again after removing the tag (git push origin :refs/tags/{tag} "
                             f"and git tag --delete {tag}), or create the release by hand with the files in {zip_path.parent}.")
    downloaded = work / "downloaded"
    downloaded.mkdir()
    run("gh", "release", "download", tag, "--repo", REPOSITORY, "--dir", str(downloaded),
        "--pattern", zip_path.name, "--pattern", checksum.name)
    remote_digest = hashlib.sha256((downloaded / zip_path.name).read_bytes()).hexdigest()
    if remote_digest != digest:
        raise ReleaseRefused(f"the uploaded {zip_path.name} has SHA-256 {remote_digest}, the build {digest}. "
                             f"Delete the release {tag} and investigate before anyone installs it.")


def release_commit(commit: str, check_only: bool) -> int:
    """Publish the version of a merge commit on main that is no longer its head (#70).

    For a pull request that was merged before the one before it was released.
    Everything comes from that commit: the package, and the version, changelog
    and support matrix read from its files. Its version must still be higher
    than every release, and the last local check must have run on a commit
    with the same content, such as the branch commit the merge brought in.
    """
    global ROOT
    require_tool("git", "Install Git for Windows.")
    require_tool("gh", "winget install GitHub.cli, then gh auth login.")
    run("git", "fetch", "--prune", "--tags", "origin")
    sha = run("git", "rev-parse", "--verify", "--quiet", f"{commit}^{{commit}}", check=False).stdout.strip()
    if not sha:
        raise ReleaseRefused(f"{commit} is not a commit of this repository")
    if run("git", "merge-base", "--is-ancestor", sha, "origin/main", check=False).returncode != 0:
        raise ReleaseRefused(f"{sha[:7]} is not on origin/main; only merged work is released")
    with tempfile.TemporaryDirectory(prefix="mahnwesen-release-") as folder:
        work = Path(folder)
        zip_path, digest = package_from_commit(sha, work)
        main_root, ROOT = ROOT, work / "source"
        try:
            info = metadata(None)
        finally:
            ROOT = main_root
        kind = "pre-release" if info["prerelease"] else "release (latest)"
        tag_free(info["tag"])
        version_progress(info["version"])
        checked = local_check_passed(sha)
        print(f"Tag:          {info['tag']} on {sha[:7]} (--commit)")
        print(f"Title:        {info['title']}")
        print(f"Kind:         {kind}")
        print(f"Package:      {zip_path.name}  sha256 {digest}")
        print(f"Local check:  {checked}")
        print(f"Notes ({info['date']}):\n" + "\n".join(f"  {line}" for line in info["notes"].splitlines()))
        if check_only:
            print("\nRelease check: OK - nothing was tagged or published (--check).")
            return 0
        publish(info, zip_path, digest, work, sha)
    print(f"\nPublished {info['title']}: https://github.com/{REPOSITORY}/releases/tag/{info['tag']}")
    print("GitHub now rebuilds the package from the tag and compares it (Verify release workflow).")
    return 0


def main(argv: list[str] | None = None) -> int:
    for stream in (sys.stdout, sys.stderr):
        try:
            stream.reconfigure(encoding="utf-8", errors="replace")
        except (AttributeError, ValueError):
            pass
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--metadata", action="store_true", help="only compare version, changelog and support matrix")
    mode.add_argument("--check", action="store_true", help="check everything and build, but publish nothing")
    parser.add_argument("--tag", help="with --metadata: the tag that must match the module version")
    parser.add_argument("--commit", help="release this merge commit on main instead of its head, when a later "
                                         "pull request was merged before this one was released")
    arguments = parser.parse_args(argv)
    if arguments.tag and not arguments.metadata:
        parser.error("--tag only goes with --metadata")
    if arguments.commit and arguments.metadata:
        parser.error("--commit does not go with --metadata")
    try:
        if arguments.commit:
            return release_commit(arguments.commit, arguments.check)
        info = metadata(arguments.tag)
        kind = "pre-release" if info["prerelease"] else "release (latest)"
        if arguments.metadata:
            print(f"Release metadata: OK ({info['tag']}, {kind}, changelog of {info['date']}, package {info['package']})")
            return 0
        head = git_state(info["tag"])
        version_progress(info["version"])
        checked = local_check_passed(head)
        with tempfile.TemporaryDirectory(prefix="mahnwesen-release-") as folder:
            work = Path(folder)
            zip_path, digest = package_from_commit(head, work)
            print(f"Tag:          {info['tag']} on {head[:7]}")
            print(f"Title:        {info['title']}")
            print(f"Kind:         {kind}")
            print(f"Package:      {zip_path.name}  sha256 {digest}")
            print(f"Local check:  {checked}")
            print(f"Notes ({info['date']}):\n" + "\n".join(f"  {line}" for line in info["notes"].splitlines()))
            if arguments.check:
                print("\nRelease check: OK - nothing was tagged or published (--check).")
                return 0
            publish(info, zip_path, digest, work)
        print(f"\nPublished {info['title']}: https://github.com/{REPOSITORY}/releases/tag/{info['tag']}")
        print("GitHub now rebuilds the package from the tag and compares it (Verify release workflow).")
        return 0
    except (ReleaseRefused, build_release.BuildError) as error:
        print(f"Release refused: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
