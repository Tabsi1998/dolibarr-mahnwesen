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

The four stages of each dunning profile: days after the due date, fee, payment period, automatic sending, email template, enabled (#20, #32).

### `llx_mahnwesen_fee` claim invoice

An open fee or interest claim can go on its own Dolibarr invoice. The claim then points at that invoice (`fk_claim_invoice`, status `invoiced`) and counts as paid once the invoice is paid; the daily job checks that (#34).

### Collective letters

With `MAHNWESEN_COLLECTIVE_LETTERS` on, the daily run and the selection first decide every invoice, then `deliverDecisions()` groups the send decisions by customer and recipient. A group of one goes through `sendNotice()` as before. A larger group goes through `sendCollectiveNotice()`: every invoice is reserved alone, under its own lock and checks, the recipient included; one letter lists the reserved invoices; each attempt keeps its own copy of it as evidence; one email goes out, and all attempts are finalised with its outcome. The track id is the customer's (`thi<id>`) (#38).

### Layouts of the dunning letter

`core/modules/mahnwesen/doc/pdf_mahnwesen_<name>.modules.php` holds one layout each, derived from `ModelePDFMahnwesen`. The frame of the letter - letterhead, addresses, the text of the template, payment terms and Dolibarr's footer - is drawn by the notice service and is the same for every layout; a layout decides how the amounts are shown. The setup lists every file it finds and stores the choice in `MAHNWESEN_ADDON_PDF`. A new layout is a new file (#76).

### `llx_mahnwesen_event`

The lasting note of every change of a case, written in the same transaction as the change: event id, type, entity, case and invoice, stage, profile code, case revision, contract version, and its delivery state. Delivery happens after the commit through Dolibarr's own trigger mechanism, so a receiver's failure can never roll back a change (#59).

### `llx_mahnwesen_interest_rate`

The base rates with the day each starts to apply. A profile with the rule "base rate plus surcharge" counts every day with the rate of that day; days without a rate carry no interest (#33).

### `llx_mahnwesen_profile` and `llx_mahnwesen_profile_match`

Dunning profiles per kind of claim, with whether automatic sending is allowed and the step after the last stage, and the product categories, customer categories and customer type that lead to each profile. An invoice's own choice lives in Dolibarr's field `mahnwesen_profile` on the invoice (#32).

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
