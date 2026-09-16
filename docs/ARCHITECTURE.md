# Architecture

## Design constraints

- external/custom Dolibarr module
- no core file modifications
- no writes to the customer invoice amount/status for dunning fees
- own persistent workflow data
- all state-changing actions permission-checked and CSRF-protected

## Main components

### `DunningManager`

Responsible for scanning overdue invoices, calculating stages, synchronizing cases, pause/resume lifecycle, and history state.

### `DunningNoticeService`

Responsible for recipient resolution, native email-template loading, substitutions, PDF generation, send reservation, sending, and send finalization.

### `ActionsMahnwesen`

Dolibarr hook class used for integrations such as native email-template types/help.

## Tables

### `llx_mahnwesen_case`

One persistent dunning case per invoice/entity.

### `llx_mahnwesen_history`

Append-oriented audit/event history for lifecycle and send actions.

### `llx_mahnwesen_rule`

The four stage rules per entity: days after the due date, enabled, automatic sending and the business fee. The private-person fees and the other settings are Dolibarr constants for now (#20).

### `llx_mahnwesen_attempt`

Operational delivery state and the immutable content/amount/recipient/artifact snapshot used for one mail attempt. Requested invoice attachments are copied to an attempt-specific file before SMTP so later invoice-PDF regeneration cannot change the audited bytes.

### `llx_mahnwesen_attempt_file`

One immutable metadata row per transmitted document, including role, display name, snapshot path, MIME type, size and SHA-256 hash.

### `llx_mahnwesen_run`

Persistent result and counters for every cron and automation dry run.

### `llx_mahnwesen_pause`

Separate dated/indefinite pause lifecycle. Workflow due dates are no longer overloaded as pause deadlines.

### `llx_mahnwesen_fee`

Module-owned subledger for fees stated in successfully delivered notices. It is not an invoice or accounting entry.

## Core data ownership

Dolibarr remains authoritative for:

- invoice status
- due date
- invoice totals
- payments/credits/deposits
- third-party/contact master data
- SMTP/mail configuration
- native email templates

Mahnwesen remains authoritative for:

- dunning workflow state
- pause state
- dunning history
- send reservations/outcomes
- delivery snapshots and recovery decisions
- exact per-attempt attachment evidence
- automation run history and overlap lock
- pause lifecycle and fee subledger
- configured dunning behavior

## Compatibility strategy

Prefer public/core-stable APIs and hooks over direct version-specific implementation. Version-specific adapters should be isolated when unavoidable.


## Sequential workflow gate

The due-date calculation and the workflow transition are separate. `current_level` stores the time/calendar stage, while `next_required_level` is derived from successful immutable history rows. Manual and automatic sends must both pass the same sequential guard. A later time threshold therefore cannot bypass an earlier unsent stage.

## Native invoice integration

The module uses Dolibarr's `invoicecard` hook for the context-sensitive dunning action. It does not modify `compta/facture/card.php`. The composer renders Dolibarr's native `FormMail` component while its controlled service retains reservation, revalidation and final `CMailFile` delivery ownership.

## Agenda projection

Module history is authoritative. A projection layer mirrors completed/non-pending history rows to Dolibarr `ActionComm` records linked to the invoice. `ref_ext=mahnwesen-history-<history-id>` is used as the idempotency key. Agenda failures are logged but do not roll back the already committed dunning workflow transaction.
