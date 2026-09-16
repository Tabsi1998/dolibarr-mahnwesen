# Dolibarr compatibility

The module is intentionally developed as a multi-version external module instead of a one-major-version fork.

## Target matrix

| Dolibarr | Status | Notes |
|---|---|---|
| 24.x | Runtime-checked (24.0.1) | Automated runtime checks; 24.0.0 itself ends every cron run with a fatal error in Dolibarr's cron shutdown handler (Dolibarr #39801), use 24.0.1 or later |
| 23.x | Runtime-checked (23.0.4) | Automated runtime checks |
| 22.x | Runtime-checked (22.0.5) | Automated runtime checks; primary production reference |
| 21.x | Runtime-checked (21.0.4) | Automated runtime checks; the last upstream release was 21.0.4 on 2025-09-15 |
| 20.x | Best-effort candidate | Not part of the guaranteed matrix |

The module descriptor uses Dolibarr 21 as the minimum. "Runtime-checked" means the local runtime checks install the named release from the official Docker image (24.0.1 is built from Dolibarr's docker repository until its image is published) and pass: activation, synchronisation, every page and hook, access for sales representatives, a manual send with the attachment hashes compared against the delivered bytes, automatic sending through Dolibarr's cron runner, and no PHP messages from module code. A staging smoke test with the real mail server stays required before production.

## Compatibility rules

1. Do not put a Dolibarr major version in the module name or repository name.
2. Keep one code line for supported majors whenever practical.
3. Prefer feature detection over hard-coded version checks.
4. Keep version-specific code in small compatibility helpers if needed.
5. CI downloads official Dolibarr 21/22/23/24 source files and checks the exact APIs used by the module, including native `FormMail`, upload-session and document-preview methods.
6. The runtime checks (`python scripts/local_check.py --only runtime`, see `tests/runtime/`) run the module in each supported Dolibarr. They found that an API being present is not enough: the access check and the invoice card hook were both wrong at runtime while every static check passed.

## Core integration points currently relied on

- ModuleBuilder/Dolibarr module descriptor and module parts
- `emailtemplates` hook context and `emailElementlist`
- `invoicecard` hook integration on customer invoices
- `ActionComm` invoice-linked Agenda projection
- Dolibarr email sender profiles (`c_email_senderprofile`)
- `Facture` / common invoice remaining amount
- `CMailFile`
- `FormMail`
- Dolibarr PDF helpers
- standard third-party/contact data

## PHP

The declared and CI-tested minimum is PHP 7.4. CI also lints on supported PHP 8.x versions.
