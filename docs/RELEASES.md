# Release and branch strategy

## Branches

- `main`: the tested state; every change arrives through a pull request that closes its issues
- `fix/*`, `feat/*`, `ci/*`, `docs/*`: one branch per pull request, always against `main`

## Packages

Dolibarr's module installer (`admin/modules.php`) accepts `mahnwesen-<digits and dots>.zip` and takes the module folder from that name, so every package is called that way and holds `mahnwesen/` at its root.

| Package | Name | Where | When |
|---|---|---|---|
| Development | `mahnwesen-1.0.0.10.zip` - release 1.0.0 plus 10 commits; the module shows version `1.0.0.10` | pre-release **Entwicklungsstand (main)**, tag `dev-main`, replaced by every newer `main` | after every merge |
| Release | `mahnwesen-1.0.1.zip` | release `v1.0.1`, marked latest | when a version is released |

Both come with a `.sha256` file. A development package is for testing only; install a release in production after a staging test.

Packaging is reproducible: sorted entries, fixed permissions, timestamps from the commit (`SOURCE_DATE_EPOCH`). The local check proves that two builds are byte-identical and that both names install as module `mahnwesen`.

## Publishing

One script, used locally and by GitHub:

```bash
python scripts/publish.py dev            # development package of main
python scripts/publish.py release        # release vX.Y.Z of the module version
python scripts/publish.py dev --dry-run  # build and verify any commit, upload nothing
```

Locally the script requires the newest `main` without uncommitted changes, runs `scripts/local_check.py` unless it already passed for that commit, builds `git archive` of the commit in the local check's Docker image, verifies the package against the commit, and uploads.

GitHub builds the same commit again: `ci.yml` job `publish-dev` after all checks passed on `main`, and `release.yml` when a `v*` tag appears. Whoever publishes first uploads; whoever comes second downloads the published ZIP, compares every file by SHA-256, fails on any difference and notes the successful comparison in the release text. A development build skips when a newer commit is on `main`, and on a release commit whose version has no tag yet.

## Releasing a version

1. A pull request raises `$this->version` in `core/modules/modMahnwesen.class.php` and turns `## Unreleased` in `CHANGELOG.md` into `## x.y.z`. The local check fails when the two disagree.
2. After it is merged: `python scripts/publish.py release` on the updated `main`. It creates tag `vX.Y.Z` and the release with ZIP, checksum and the changelog section as notes.
3. `release.yml` rebuilds the tag and verifies the published package.

## Runtime verification

A release is only described as runtime-checked for a Dolibarr major when the runtime checks pass against it; see `docs/COMPATIBILITY.md`. A staging smoke test with the real mail server remains required before enabling delivery in production.
