# Security and sending safety

Dunning emails are externally visible and effectively irreversible. The module therefore uses a fail-closed model.

## Current safeguards

- no Dolibarr core modification
- no modification of invoice totals by dunning fees
- permission for case management separated from permission to send
- manual sending requires explicit configuration enablement
- automatic sending requires a separate global enablement
- per-stage automatic-send configuration
- recipient validation
- Dolibarr invoice permission, restricted-area and active-entity checks
- fail-closed BILLING-contact lookup
- atomic amount/stage/cooldown/recipient revalidation under a case lock
- attempt reservation before PDF generation and the mailer call
- unique per-attempt PDF plus attachment SHA-256 hashes
- immutable snapshots and SHA-256 evidence for every additional attachment
- configurable per-run, per-customer and per-stage failure limits
- persistent cron/dry-run audit records
- entity-scoped overlap lock for automatic cron delivery
- append-only audit history and a separate recovery queue
- ambiguous SMTP outcomes block retries until operator resolution
- foreign-currency invoices are blocked instead of mixing currencies
- CSRF protection on state-changing module pages
- pause blocks normal sending

## Supported security line

Security fixes are applied to the current `1.0.x` release line. Older
test builds should be upgraded before enabling any mail delivery.

## Reporting a security problem

Do not include real customer data, invoice PDFs, SMTP credentials, or production database dumps in a public issue. Use the repository's private [GitHub security advisory form](https://github.com/Tabsi1998/dolibarr-mahnwesen/security/advisories/new).
