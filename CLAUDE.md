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
python scripts/local_check.py --only runtime --keep-services   # leave the Dolibarrs running
python scripts/local_check.py --list          # the steps, without running them
```

Results: `.local-testing/local-check.json`, logs in `.local-testing/logs/`,
live progress in `.local-testing/local-check.progress.json`. All of it is
ignored by Git.

| Group | Mirrors | Runs |
| --- | --- | --- |
| repository | - | every `*.sh` parses, no CRLF in the index, `git diff --check` over every tracked line, Gitleaks over the history and over uncommitted files |
| php | ci.yml `php-lint` matrix | `scripts/check-module.sh` in `php:7.4-cli` to `php:8.4-cli`, and proof that its lint, policy tests, language keys and contracts really ran (it skips them silently without php) |
| dolibarr | ci.yml `dolibarr-api` matrix | `scripts/check-dolibarr-api.sh` for 21.0, 22.0, 23.0 and 24.0 |
| package | ci.yml `package` | `scripts/build_release.py` from the working copy and from the snapshot, byte for byte identical, verified file by file |
| release | ci.yml `package`, release-verify.yml | `scripts/release.py --metadata`: version, dated changelog section with link, support matrix (descriptor, `PHP_VERSIONS`, `DOLIBARR_VERSIONS`, `check-dolibarr-api.sh`, ci.yml, README tables); any tag matches; a package changed since the newest release needs a new version |
| runtime | - | the module in a running Dolibarr 21.0, 22.0, 23.0 and 24.0 (official images, MariaDB, Mailpit), driven through its pages and the Dolibarr cron; see below |
| extra | - | PHP 8.4 deprecations and warnings in `tests/run.php`, printf placeholders that differ between de_DE and en_US, ShellCheck, OSV |

Tools the checks expect: Docker Desktop, Git for Windows, gitleaks. A missing
tool skips its steps with a hint.

The php, dolibarr, package and runtime steps run against
`.local-testing/snapshot`, a copy of what Git would commit with LF line endings,
as the Linux runner checks it out.

## Runtime checks

`tests/runtime/` holds a real Dolibarr test. For every version in
`RUNTIME_IMAGES` (header of `scripts/local_check.py`) the check starts, all at
once, a `dolibarr/dolibarr` container with the snapshot mounted read-only as
`custom/mahnwesen`, a MariaDB and a Mailpit, each on its own network. Database
and documents live in tmpfs, passwords are new each run, and everything is
removed afterwards. Ports: web 18021-18024, Mailpit 18121-18124.

- `fixtures.php` runs with the PHP CLI in the container: company in Austria,
  SMTP to Mailpit, modules Societe/Facture/Agenda/Cron/Mahnwesen, an admin, a
  sales representative `rtsales` for the company customer, a second
  representative `rtother` without customers, a company with a BILLING
  contact, a private customer and four validated overdue invoices with PDFs -
  one of them renamed the way other PDF models name files. It prints the ids
  as JSON. `bootstrap.php` refuses to run outside the CLI.
- `scenarios.py` drives the pages with `dolibarr_http.py` (sessions, CSRF
  tokens, forms submitted as a browser submits them - an unticked checkbox is
  not sent), reads Mailpit's API and the database, and runs the Dolibarr cron
  runner. Each entry of `SCENARIOS` becomes one step per version; `needs`
  keeps their order.
- `php-check.ini` logs every PHP message; the `php-messages` step collects
  those from module code through the ratchet (`runtime-php-<version>`).

With `--keep-services` the stacks stay up and
`.local-testing/runtime-<version>-access.json` holds the URL, the Mailpit URL
and the throwaway passwords. A bug fix gets a scenario that fails before the
fix; a new Dolibarr major gets a line in `RUNTIME_IMAGES`, `DOLIBARR_VERSIONS`,
`scripts/check-dolibarr-api.sh` and the CI matrix.

## Releases

Every merged pull request that changes the package is released as "Mahnwesen
vX.Y.Z", the same scheme as dolibarr-vereine. Such a pull request raises
`$this->version` and turns its changelog entries into `## [x.y.z] - YYYY-MM-DD`
with a link at the bottom; the local release step fails otherwise. After
Fabian's merge Claude runs, on an up-to-date `main`:

```bash
python scripts/local_check.py
python scripts/release.py --check
python scripts/release.py
```

`release-verify.yml` then rebuilds the tag and compares the published ZIP byte
for byte. Details: `docs/RELEASES.md`. The package name must stay
`module_mahnwesen-x.y.z.zip`; Dolibarr's installer derives the module folder
from it.

## Keep in step

- Raising the PHP or Dolibarr minimum means changing `phpmin` or
  `need_dolibarr_version`, the matrix in `.github/workflows/ci.yml`, and
  `PHP_VERSIONS` or `DOLIBARR_VERSIONS` (with `RUNTIME_IMAGES`) in the header of
  `scripts/local_check.py` together; the release check fails otherwise.
- `scripts/check-module.sh` holds static contracts. A contract that only greps
  the start of a call proves nothing: the one for `restrictedArea()` did so and
  kept every user locked out until the runtime checks ran (#44).
- `build_release.py` packs every tracked file outside `EXCLUDED_TOP`. A new
  developer-only top-level file or folder needs an entry there.

## Ratchet

The extra group and the whitespace check compare against
`scripts/ci-baseline.json`: known findings are debt, new ones fail. After
paying debt down, run `python scripts/local_check.py --all --record` and commit
the baseline. Debt on 2026-09-16: trailing whitespace in `admin/setup.php`
(the ShellCheck finding left with `build-release.sh`).

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
