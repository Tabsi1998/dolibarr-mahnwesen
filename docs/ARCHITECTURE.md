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

Reserved/configuration-oriented rule storage.

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
- configured dunning behavior

## Compatibility strategy

Prefer public/core-stable APIs and hooks over direct version-specific implementation. Version-specific adapters should be isolated when unavoidable.


## Sequential workflow gate

The due-date calculation and the workflow transition are separate. `current_level` stores the time/calendar stage, while `next_required_level` is derived from successful immutable history rows. Manual and automatic sends must both pass the same sequential guard. A later time threshold therefore cannot bypass an earlier unsent stage.

## Native invoice integration

The module uses Dolibarr's `invoicecard` hook for the context-sensitive dunning action. It does not modify `compta/facture/card.php`. The composer is module-owned but follows Dolibarr mail conventions and uses native email templates, sender profiles and `CMailFile`.

## Agenda projection

Module history is authoritative. A projection layer mirrors completed/non-pending history rows to Dolibarr `ActionComm` records linked to the invoice. `ref_ext=mahnwesen-history-<history-id>` is used as the idempotency key. Agenda failures are logged but do not roll back the already committed dunning workflow transaction.
