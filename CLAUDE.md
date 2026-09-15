# CLAUDE.md

Notes for Claude Code sessions on dolibarr-mahnwesen: how the local checks are
set up and how to extend them. Answer the owner (Tabsi1998) in German. Commits
and PR titles stay English, in the conventional style of the history
(`feat:`, `fix:`, `chore:`, `release:`). The module rules in `CONTRIBUTING.md`
apply to every change: invoice data stays read-only, automatic sending stays
off by default, German and English language keys change together.

## Local first, GitHub second

GitHub Actions is the second confirmation. Before every push:

```bash
python scripts/local_check.py                 # everything but extra
python scripts/local_check.py --all           # plus the gates GitHub does not run
python scripts/local_check.py --only php,package
python scripts/local_check.py --list          # the steps, without running them
```

Results: `.local-testing/local-check.json`, logs in `.local-testing/logs/`,
live progress in `.local-testing/local-check.progress.json`. All of it is
ignored by Git.

| Group | Mirrors | Runs |
| --- | --- | --- |
| repository | - | every `*.sh` parses, no CRLF in the index, `git diff --check` over every tracked line, Gitleaks over the history and over uncommitted files |
| php | ci.yml `php-lint` matrix | `scripts/check-module.sh` in `php:7.4-cli` to `php:8.4-cli`, and proof that its lint, policy tests, language keys and contracts really ran (it skips them silently without php) |
| dolibarr | ci.yml `dolibarr-api` matrix | `scripts/check-dolibarr-api.sh` for 21.0, 22.0 and 23.0 |
| package | ci.yml `build-package` | `scripts/build-release.sh` in `local-ci/mahnwesen-zip:8.2` (php:8.2-cli plus zip, built on first use), the ZIP checked file by file, and the Windows fallback of the script compared with it byte by byte |
| release | release.yml | module version is x.y.z and matches the newest changelog entry and any tag; `phpmin` and `need_dolibarr_version` equal the lowest entry of each matrix |
| extra | - | PHP 8.4 deprecations and warnings in `tests/run.php`, printf placeholders that differ between de_DE and en_US, ShellCheck, OSV |

Tools the checks expect: Docker Desktop, Git for Windows, gitleaks. A missing
tool skips its steps with a hint.

The php, dolibarr and package steps run against `.local-testing/snapshot`, a
copy of what Git would commit with LF line endings, as the Linux runner checks
it out.

## Keep in step

- Raising the PHP or Dolibarr minimum means changing `phpmin` or
  `need_dolibarr_version`, the matrix in `.github/workflows/ci.yml`, and
  `PHP_VERSIONS` or `DOLIBARR_VERSIONS` in the header of
  `scripts/local_check.py` together; the release check fails otherwise.
- `build-release.sh` packs every file it does not exclude. Local-only folders
  such as `.ci-panel/` must stay out of Git, or the release ZIP carries them.

## Ratchet

The extra group and the whitespace check compare against
`scripts/ci-baseline.json`: known findings are debt, new ones fail. After
paying debt down, run `python scripts/local_check.py --all --record` and commit
the baseline. Debt on 2026-09-15: SC2155 in `scripts/build-release.sh`, trailing
whitespace in `admin/setup.php`.

## Extending the checks

`scripts/local_check.py` has three parts:

1. **Header** - paths, groups, the PHP and Dolibarr matrices, package rules.
2. **Shared core** - `Step`, `Context`, the runner, the ratchet, Gitleaks, OSV,
   ShellCheck. OmniFM, IT-Tabelander and THE-LION_SQUAD-eSPORT-Webseite carry
   the same copy; a fix here is worth porting there.
3. **dolibarr steps** and `plan()`.

A step is a function `(context) -> str`. It returns its one-line result,
raises `StepFailed` with the reason and how to fix it, or `StepSkipped` when it
cannot run on this machine. Register it with
`Step(group, name, describe, action, needs)`; `needs` names steps of the same
group, or `group/name` across groups. `in_php(context, version, script)` runs a
bash script inside a PHP image against the snapshot. A gate that counts
findings goes through `ratchet(context, key, found, what)`.

## Machine-local helpers (not in Git)

- `.ci-panel/test_checks.py` with `.vscode/settings.json`: every step in the VS
  Code Testing panel through pytest. Hidden through `.git/info/exclude`.
- `C:\Programmieren\check-all.py --serve`: live dashboard over all
  repositories. `C:\Programmieren\Programmieren.code-workspace` opens all five.
