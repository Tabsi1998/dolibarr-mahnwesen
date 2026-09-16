# Contributing

Contributions are welcome. Never use production customer data, invoice PDFs,
mail credentials or database dumps in issues or tests.

## Local checks

Run before opening a pull request:

```bash
bash scripts/check-module.sh
bash scripts/check-dolibarr-api.sh 21.0
bash scripts/check-dolibarr-api.sh 22.0
bash scripts/check-dolibarr-api.sh 23.0
bash scripts/check-dolibarr-api.sh 24.0
```

Or run all of them in one go - on PHP 7.4 to 8.4, with the release package
checked as well and the module running in Dolibarr 21 to 24 - with
`python scripts/local_check.py` (needs Docker). A bug fix comes with a runtime
scenario in `tests/runtime/scenarios.py` that fails without the fix.

PHP 7.4 is the minimum syntax target. A change to sending, reservations,
permissions, entities, amounts or recipients must include a regression test or
an explicit static safety contract.

## Pull requests

- keep invoice data read-only;
- keep automatic sending disabled by default;
- use the central reservation/eligibility path for every delivery;
- keep `llx_mahnwesen_history` append-only;
- update German and English language keys together;
- update `CHANGELOG.md` for user-visible changes;
- a change to the installable package raises the module version and carries its dated changelog section, see [docs/RELEASES.md](docs/RELEASES.md).

Security issues must follow [SECURITY.md](SECURITY.md), not a public issue.
