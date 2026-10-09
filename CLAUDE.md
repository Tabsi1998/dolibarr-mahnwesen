# CLAUDE.md

Notes for everyone working on dolibarr-mahnwesen, including Claude Code. The
module rules in `CONTRIBUTING.md` apply to every change: invoice data stays
read-only, automatic sending stays off by default, German and English
language keys change together. Commits and PR titles are English, in the
style of the history (`feat:`, `fix:`, `chore:`, `release:`, `docs:`).

## Local first, GitHub second

GitHub Actions is the second confirmation. Before every push:

```bash
python scripts/local_check.py                 # everything but extra
python scripts/local_check.py --all           # plus the gates GitHub does not run
python scripts/local_check.py --only php,package
python scripts/local_check.py --only package,runtime --keep-services   # leave the Dolibarrs running
python scripts/local_check.py --list          # the steps, without running them
```

Results: `.local-testing/local-check.json`, logs in `.local-testing/logs/`.
Tools: Docker Desktop, Git for Windows, gitleaks; a missing tool skips its steps.

| Group | Runs |
| --- | --- |
| repository | shell scripts parse, no CRLF, `git diff --check`, Gitleaks |
| php | `scripts/check-module.sh` in PHP 7.4 to 8.4, PHPStan level 3 (`scripts/phpstan.neon`) |
| dolibarr | `scripts/check-dolibarr-api.sh` for 21.0, 22.0, 23.0, 24.0 |
| package | `scripts/build_release.py` twice, byte for byte identical |
| release | `scripts/release.py --metadata`: version, changelog section, support matrix |
| runtime | the package installed through Dolibarr's installer in Dolibarr 21-24 (Docker, MariaDB, Mailpit), upgraded from the previous release, driven through its pages and the cron |
| extra | PHP 8.4 deprecations, placeholders de/en, ShellCheck, OSV |

## Runtime checks

`tests/runtime/`: `fixtures.php` builds test data in stages (PHP CLI in the
container), `scenarios.py` drives the pages like a browser and reads Mailpit
and the database, `dolibarr_http.py` holds the session and form helpers. Each
entry of `SCENARIOS` is one step per Dolibarr version; `needs` keeps the
order. Ports: web 18021-18024, Mailpit 18121-18124. A bug fix gets a scenario
that fails before the fix.

## Releases

A pull request that changes the package raises `$this->version` in
`core/modules/modMahnwesen.class.php` and adds `## [x.y.z] - YYYY-MM-DD` with
its link to `CHANGELOG.md`. After the merge, on an up-to-date `main`:

```bash
python scripts/local_check.py
python scripts/release.py --check
python scripts/release.py
```

Details: `docs/RELEASES.md`. The package name stays
`module_mahnwesen-x.y.z.zip`.

## Keep in step

- PHP or Dolibarr minimum: `phpmin`/`need_dolibarr_version`, the matrix in
  `.github/workflows/ci.yml`, `PHP_VERSIONS`/`DOLIBARR_VERSIONS`/
  `RUNTIME_IMAGES` in `scripts/local_check.py` and the README tables.
- A new top-level developer file needs an entry in `EXCLUDED_TOP` of
  `scripts/build_release.py`.
- Known findings live in `scripts/ci-baseline.json` (ratchet); new ones fail.
  After paying debt down: `python scripts/local_check.py --all --record`.
- A new check step: `Step(group, name, describe, action, needs)` in
  `scripts/local_check.py`; it returns its result line or raises
  `StepFailed`/`StepSkipped`.
