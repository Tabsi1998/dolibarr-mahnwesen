# Dolibarr compatibility

The module is intentionally developed as a multi-version external module instead of a one-major-version fork.

## Target matrix

| Dolibarr | Status | Notes |
|---|---|---|
| 23.x | Target / API reviewed | Native email-template hook remains available; runtime smoke test pending |
| 22.x | Runtime tested | Primary development/runtime reference so far |
| 21.x | Target / API reviewed | ModuleBuilder hook declaration and native email-template hook are present; runtime smoke test pending |
| 20.x | Best-effort candidate | Not part of the guaranteed matrix yet |

The module descriptor currently uses Dolibarr 21 as the minimum target. This means compatibility with 21+ is intended, but a version is only marked runtime-verified after an actual installation test.

## Compatibility rules

1. Do not put a Dolibarr major version in the module name or repository name.
2. Keep one code line for supported majors whenever practical.
3. Prefer feature detection over hard-coded version checks.
4. Keep version-specific code in small compatibility helpers if needed.
5. CI downloads official Dolibarr 21/22/23 source files and checks the exact APIs used by the module.
6. Runtime smoke tests remain the final authority.

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
