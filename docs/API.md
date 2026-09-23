# REST API

Reading the dunning status through Dolibarr's own REST API (module *API/Web services*). Every call is read-only: nothing is sent, no stage moves, no fee changes.

## Views and rights

| View | Right | What it reaches |
| --- | --- | --- |
| Operations | `mahnwesen > api > operations` | The dunning state of the whole entity, including the automation and the last run. For internal or technical users of the organisation itself. |
| Customer | `mahnwesen > api > customer` | Only the customers the user is the sales representative of in Dolibarr. That assignment is the proof; a customer id in the request is checked against it and never trusted by itself. |

A portal therefore gets a technical user with the customer right, assigned to exactly the customers it may serve. It never reaches the operations view.

## Endpoints

| Method | Path | Answer |
| --- | --- | --- |
| GET | `/mahnwesen/invoices/{id}` | The dunning status of one customer invoice |
| GET | `/mahnwesen/thirdparties/{id}?page=0&limit=25` | The cases of one customer, paginated |
| GET | `/mahnwesen/status` | Automation state and last run (operations only) |

Dolibarr builds the OpenAPI description itself from these endpoints, at `/api/index.php/explorer`.

## Archived dunning letters

| Method | Path | Answer |
| --- | --- | --- |
| GET | `/mahnwesen/invoices/{id}/documents?page=0&limit=25` | The dunning letters really sent for that invoice |
| GET | `/mahnwesen/documents/{id}` | One archived letter, base64 encoded |

The list holds `document_id`, `attempt_id`, `invoice_id`, `invoice_ref`, `stage`, `sent_at`, `filename`, `mime_type`, `size_bytes` and `sha256`. The single document adds `encoding`, `content` and `sha256_now`, the hash of the bytes handed out, so a reader can compare it with the one recorded at the time of sending.

- Only deliveries that went out are listed. A draft, a preview and an attempt whose outcome is unclear are not; they answer `404` like anything else the caller may not see.
- The bytes are the archived ones. Nothing is generated again, so a changed amount, name or template never reaches an old letter.
- A file that is gone from the archive answers `410`, never an invented original. A file larger than `MAHNWESEN_API_MAX_DOCUMENT_MB` (20) answers `413`.
- The email body, BCC, internal notes and other attachments are never part of it. Rights are checked per call, so a withdrawn assignment ends the access at once.

## What a case tells

`invoice_id`, `invoice_ref`, `thirdparty_id`, `entity`, `case_id`, `case_revision`, `status`, `paused`, `stage`, `profile_code`, `currency`, `invoice_open`, `fee_open`, `interest_open`, `claims_on_own_invoice`, `due_date`, `next_action_at`, `changed_at`.

- Amounts are numbers with two decimals in the entity's currency (`currency`).
- `invoice_open` is Dolibarr's own remaining amount of the invoice. `fee_open` and `interest_open` are the open claims of the ledger, kept apart from it. What went onto an invoice of its own (#34) is in `claims_on_own_invoice` and is not counted again.
- `case_revision` counts every change of the case, so a reader can tell whether it has the newer state. `changed_at` is when the case was last written.

## What it never tells

Internal notes, email texts, recipients, BCC, attachment paths, reasons for a pause or a block, and anything of another customer. An invoice of another entity, of another customer, or one that does not exist all answer the same way: `404 Not found`, so nothing leaks about what exists.

## Errors

| Code | Meaning |
| --- | --- |
| 401 | The caller has neither right |
| 404 | Not found, or outside the caller's customers |
| 500 | The dunning cases could not be read |

A failure of the API is not a statement that nothing is owed.
