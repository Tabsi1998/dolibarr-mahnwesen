#!/usr/bin/env python3
"""Build the installable package module_mahnwesen-x.y.z.zip, byte for byte reproducible.

The ZIP holds one folder, mahnwesen/, with everything Dolibarr needs and nothing a
developer uses: no tests, scripts, CI files or developer documents. Entries are
sorted, dated 1980-01-01 and stored uncompressed, so the same source gives the
same SHA-256 on every machine - that is how the release workflow on GitHub
proves the published package was built from the tagged commit.

The file name carries only x.y.z, even for a beta: Dolibarr's "Deploy an
external module" accepts nothing else (htdocs/admin/modules.php). The beta
marker lives in the tag and in the module version.

Usage:
    python scripts/build_release.py                    from the working copy
    python scripts/build_release.py --source DIR       from another folder (a snapshot, a git archive)
    python scripts/build_release.py --out dist         where the ZIP and its .sha256 go (default dist)
    python scripts/build_release.py --verify ZIP       check an existing package, build nothing

Standard library only.
"""

from __future__ import annotations

import argparse
import hashlib
import re
import shutil
import subprocess
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE = "mahnwesen"
DESCRIPTOR = Path("core/modules/modMahnwesen.class.php")

# Top-level entries of the repository that are not part of the module.
EXCLUDED_TOP = {
    ".git", ".github", ".gitignore", ".gitattributes", ".gitleaks.toml", ".editorconfig",
    ".local-testing", ".ci-panel", ".vscode", ".idea", "dist", "build", "scripts", "tests",
    "CLAUDE.md", "CONTRIBUTING.md",
}
# File names that never belong in a package, wherever they are.
EXCLUDED_NAMES = {"__pycache__", ".DS_Store", "Thumbs.db"}
EXCLUDED_SUFFIXES = (".pyc", ".log", ".tmp", ".swp")

# What an installation cannot work without.
REQUIRED = (
    "core/modules/modMahnwesen.class.php",
    "langs/en_US/mahnwesen.lang",
    "langs/de_DE/mahnwesen.lang",
    "LICENSE",
    "README.md",
    "CHANGELOG.md",
)

# The same rule Dolibarr applies to an uploaded package name.
DOLIBARR_ZIP_RULE = re.compile(r"^(module[a-zA-Z0-9]*_|theme_|).*\-([0-9][0-9\.]*)(\s\(\d+\)\s)?\.zip$", re.IGNORECASE)
VERSION_RULE = re.compile(r"^(\d+)\.(\d+)\.(\d+)(-beta)?$")
FIXED_DATE = (1980, 1, 1, 0, 0, 0)


class BuildError(Exception):
    """The package cannot be built or is not what Dolibarr should install."""


def module_version(source: Path) -> str:
    text = (source / DESCRIPTOR).read_text(encoding="utf-8")
    match = re.search(r"\$this->version\s*=\s*'([^']+)'", text)
    if not match:
        raise BuildError(f"{DESCRIPTOR} declares no $this->version")
    version = match.group(1)
    if not VERSION_RULE.match(version):
        raise BuildError(f"module version {version!r} is neither x.y.z nor x.y.z-beta")
    return version


def package_name(version: str) -> str:
    """module_mahnwesen-x.y.z.zip - the beta marker cannot be part of the name."""
    numeric = VERSION_RULE.match(version).group(0).replace("-beta", "")
    name = f"module_{MODULE}-{numeric}.zip"
    if not DOLIBARR_ZIP_RULE.match(name):
        raise BuildError(f"{name} does not match Dolibarr's package name rule")
    return name


def included(relative: Path) -> bool:
    parts = relative.parts
    if not parts or parts[0] in EXCLUDED_TOP:
        return False
    if any(part in EXCLUDED_NAMES for part in parts):
        return False
    return not relative.name.endswith(EXCLUDED_SUFFIXES)


def tracked_files(source: Path) -> list[Path] | None:
    """Files Git tracks in a working copy, or None when source is not one.

    In a working copy only tracked files may reach the package: an earlier
    build's output, a log or a scratch file would otherwise ship. A snapshot or
    a git archive has no .git and consists of committed files already.
    """
    if not (source / ".git").exists() or not shutil.which("git"):
        return None
    completed = subprocess.run(["git", "ls-files", "-z", "--cached"], cwd=str(source), capture_output=True)
    if completed.returncode != 0:
        raise BuildError("git ls-files failed: " + completed.stderr.decode("utf-8", "replace").strip())
    return [source / name for name in completed.stdout.decode("utf-8").split("\0") if name]


def package_files(source: Path, output: Path | None = None) -> list[str]:
    """The module's files below source. The output folder never counts, wherever it lies."""
    source = source.resolve()
    output = output.resolve() if output is not None else None

    def wanted(path: Path) -> bool:
        if output is not None and (path == output or output in path.parents):
            return False
        return path.is_file() and included(path.relative_to(source))

    candidates = tracked_files(source)
    if candidates is None:
        candidates = list(source.rglob("*"))
    files = sorted(path.relative_to(source).as_posix() for path in candidates if wanted(path))
    missing = [name for name in REQUIRED if name not in files]
    if missing:
        raise BuildError(f"the source lacks {', '.join(missing)}")
    return files


def build(source: Path, out: Path) -> tuple[Path, str]:
    version = module_version(source)
    files = package_files(source, out)
    out.mkdir(parents=True, exist_ok=True)
    archive = out / package_name(version)
    directories = sorted({f"{MODULE}/" + "/".join(Path(name).parts[:depth]) + "/"
                          for name in files for depth in range(1, len(Path(name).parts))}
                         | {f"{MODULE}/"})
    with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_STORED) as bundle:
        for directory in directories:
            info = zipfile.ZipInfo(directory, FIXED_DATE)
            info.external_attr = (0o40755 << 16) | 0x10
            info.create_system = 3
            bundle.writestr(info, b"")
        for name in files:
            info = zipfile.ZipInfo(f"{MODULE}/{name}", FIXED_DATE)
            info.external_attr = 0o100644 << 16
            info.create_system = 3
            info.compress_type = zipfile.ZIP_STORED
            bundle.writestr(info, (source / name).read_bytes())
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    (out / (archive.name + ".sha256")).write_text(f"{digest}  {archive.name}\n", encoding="ascii", newline="\n")
    return archive, digest


def verify(archive: Path, source: Path | None = None) -> list[str]:
    """Everything that would make the ZIP a bad thing to install."""
    problems = []
    if not DOLIBARR_ZIP_RULE.match(archive.name):
        problems.append(f"{archive.name} does not match Dolibarr's package name rule")
    with zipfile.ZipFile(archive) as bundle:
        corrupt = bundle.testzip()
        if corrupt:
            problems.append(f"corrupt member {corrupt}")
        names = [info.filename for info in bundle.infolist()]
        contents = {info.filename[len(MODULE) + 1:]: bundle.read(info) for info in bundle.infolist()
                    if not info.is_dir() and info.filename.startswith(MODULE + "/")}
    if any("\\" in name for name in names):
        problems.append("entries use backslashes, which a Linux server unpacks as flat file names")
    tops = {name.split("/", 1)[0] for name in names}
    if tops != {MODULE}:
        problems.append(f"the top level is {sorted(tops)}, not only {MODULE}/")
    file_names = [name for name in names if not name.endswith("/")]
    if file_names != sorted(file_names):
        problems.append("file entries are not sorted, so two builds could differ")
    for name in REQUIRED:
        if name not in contents:
            problems.append(f"missing {name}")
    leaked = sorted(name for name in contents if not included(Path(name)))
    if leaked:
        problems.append(f"contains developer files: {', '.join(leaked[:5])}")
    checksum = archive.with_name(archive.name + ".sha256")
    if not checksum.is_file():
        problems.append(f"no {checksum.name} next to the ZIP")
    elif checksum.read_text(encoding="ascii").split()[0] != hashlib.sha256(archive.read_bytes()).hexdigest():
        problems.append(f"{checksum.name} does not match the ZIP")
    if source is not None:
        expected = set(package_files(source, archive.parent))
        absent = sorted(expected - set(contents))
        foreign = sorted(set(contents) - expected)
        if absent:
            problems.append(f"{len(absent)} module files are missing: {', '.join(absent[:5])}")
        if foreign:
            problems.append(f"{len(foreign)} files are not in the source: {', '.join(foreign[:5])}")
        differ = sorted(name for name in expected & set(contents) if (source / name).read_bytes() != contents[name])
        if differ:
            problems.append(f"{len(differ)} files differ from the source, for example {differ[0]}")
        version = module_version(source)
        if archive.name != package_name(version):
            problems.append(f"named {archive.name}, but version {version} gives {package_name(version)}")
    return problems


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--source", type=Path, default=ROOT, help="module source folder (default: this repository)")
    parser.add_argument("--out", type=Path, default=ROOT / "dist", help="output folder (default: dist)")
    parser.add_argument("--verify", type=Path, help="verify this ZIP against --source instead of building")
    arguments = parser.parse_args(argv)
    try:
        if arguments.verify:
            problems = verify(arguments.verify, arguments.source)
            if problems:
                print("The package is not what Dolibarr should install:\n  " + "\n  ".join(problems), file=sys.stderr)
                return 1
            print(f"{arguments.verify.name}: OK")
            return 0
        archive, digest = build(arguments.source.resolve(), arguments.out.resolve())
        problems = verify(archive, arguments.source.resolve())
        if problems:
            print("The package is not what Dolibarr should install:\n  " + "\n  ".join(problems), file=sys.stderr)
            return 1
        print(f"{archive}\nsha256 {digest}")
        return 0
    except BuildError as error:
        print(str(error), file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
