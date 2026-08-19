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
- duplicate/pending-send guard
- send attempt reservation before the mailer call
- audit/history records
- CSRF protection on state-changing module pages
- pause blocks normal sending

## Required sequential safeguard

Before automatic sending is considered production-ready, it must enforce the sequential workflow described in `docs/WORKFLOW.md`.

A later stage must not be sent when an earlier required stage is incomplete, even if the invoice is old enough for the later threshold.

## Reporting a security problem

Do not include real customer data, invoice PDFs, SMTP credentials, or production database dumps in a public issue. Use a private communication channel with the repository owner for sensitive reports.
