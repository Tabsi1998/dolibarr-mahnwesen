# Releases

The Mahnwesen and Vereine modules (Tabsi1998/dolibarr-vereine) follow the same scheme.

## Versions

| Kind | Tag and module version | GitHub | Package |
| --- | --- | --- | --- |
| Bugfix release | `v1.0.1` | Release, marked latest | `module_mahnwesen-1.0.1.zip` |
| Feature release | `v1.1.0` | Release, marked latest | `module_mahnwesen-1.1.0.zip` |
| Beta of a later release | `v1.1.0-beta` | Pre-release | `module_mahnwesen-1.1.0.zip` |

The package name carries no `-beta`: Dolibarr's *Deploy an external module* (`admin/modules.php`) only accepts names ending in `-x.y.z.zip` and takes the module folder from what precedes it. The beta marker is in the tag, the release title and the module version Dolibarr shows in its module list.

Each minor version has a milestone (`1.0`, `1.1`, ...). Bugfix versions go into the milestone of the version they fix.

## One release per merged pull request

Every pull request that changes the installable package is released as soon as it is merged, so the newest state can always be installed from the releases page. Pull requests that only touch what the package leaves out - `scripts/`, `tests/`, `.github/`, `CLAUDE.md`, `CONTRIBUTING.md` - are not released.

Such a pull request therefore carries its version itself:

1. Raise `$this->version` in `core/modules/modMahnwesen.class.php`: the next patch version for fixes and small additions within a milestone (`1.0.1` to `1.0.2`), the next minor version for the first pull request of a new milestone (`1.0.4` to `1.1.0`).
2. Move its `Unreleased` entries of `CHANGELOG.md` into a section `## [x.y.z] - YYYY-MM-DD` and add the link at the bottom. That section becomes the text of the GitHub release.
3. `python scripts/local_check.py` must pass. Its release step fails when the package changed since the newest release but the version did not.

## Publishing

Right after the merge, on an up-to-date `main`:

```bash
python scripts/local_check.py             # the full check for exactly this commit
python scripts/release.py --check         # everything except tag and publication
python scripts/release.py                 # tag, GitHub release, package upload
```

`release.py` refuses unless:

- `main` is checked out, clean and equal to `origin/main`;
- the module version is `x.y.z` or `x.y.z-beta`;
- `CHANGELOG.md` has a non-empty section for it, dated today or earlier, with its link;
- neither the tag nor a release of that name exists yet, and the version is higher than every released one;
- `.local-testing/local-check.json` reports a complete, green local check for this commit, runtime checks included.

It then builds the package from the committed files (`git archive`) with `scripts/build_release.py`, verifies it, creates the annotated tag `v<version>`, pushes it, creates the GitHub release - a pre-release for betas, otherwise the latest release - with the changelog section as its notes, uploads the ZIP and its `.sha256`, downloads both again and compares the checksum.

## A merge that was not released in time

`release.py` releases the head of `main`. When a second pull request is merged before the first one is released, release the first one from its merge commit, then the second one as usual:

```bash
python scripts/local_check.py             # on a commit with the content of the first merge,
                                          # for example its branch commit (git switch --detach <sha>)
git switch main
python scripts/release.py --check --commit <merge commit of the first pull request>
python scripts/release.py --commit <merge commit of the first pull request>
python scripts/local_check.py             # then the head of main
python scripts/release.py
```

`--commit` refuses a commit that is not on `origin/main`, a version that is not higher than every release, an existing tag or release, and a local check that did not run on a commit with the same content. Package, version, changelog section and support matrix all come from that commit.

## GitHub's second confirmation

`.github/workflows/release-verify.yml` runs when a release is published. It checks out the tag, builds the package again and fails when it differs from the uploaded asset byte for byte, or when the pre-release flag does not match the version. `build_release.py` packs reproducibly - sorted entries, stored, fixed dates - so a difference means the published ZIP was not built from that tag. On every push and pull request `ci.yml` checks the release metadata and builds the package twice.

## Runtime verification

A Dolibarr major is described as runtime-checked only when the runtime checks pass against it; see `docs/COMPATIBILITY.md`. A staging test with the real mail server remains required before enabling delivery in production.
