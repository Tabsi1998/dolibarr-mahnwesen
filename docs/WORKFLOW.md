# Dunning workflow model

## Principle

A due-date threshold answers only this question:

> Which dunning level is the invoice old enough for?

It must **not** answer:

> Which notice may be sent next?

Those are separate concepts.

## Two levels

### Time-eligible level

Calculated from the invoice due date and configured thresholds.

Example at 25 days overdue:

- payment reminder: eligible
- 1st dunning notice: eligible
- 2nd dunning notice: eligible
- 3rd dunning notice: not yet eligible

Time-eligible level = `2nd dunning notice`.

### Workflow-required level

The next stage that has not yet been successfully completed.

If only the payment reminder has been sent, workflow-required level = `1st dunning notice`.

## Allowed transition

A stage is completed by a successful send, or by an authorized user who skips it on the invoice's dunning tab with a mandatory reason, recorded as `stage_skipped`. A stage can therefore be completed by:

- successful send, or
- explicit authorized waiver/skip with a mandatory reason.

A failed or pending send does not complete the stage.

## Required guard

The effective next action is the lowest incomplete stage that is time-eligible.

Pseudo logic:

```text
eligible = calculate_time_eligible_level(invoice)
completed = successful_sent_or_waived_levels(case_history)
next_required = first_stage_not_completed()

if next_required <= eligible:
    action = next_required
else:
    action = none_yet
```

A later stage must never be selected just because its date threshold has been reached.

## Manual and automatic parity

Manual sending and automatic sending must call the same eligibility/guard service. There must not be a relaxed cron-only path.

## Pause

While paused:

- no manual/automatic dunning send is allowed unless an authorized user explicitly ends the pause first
- time eligibility may continue increasing in the background
- workflow sequence remains frozen

For a dated pause, the daily job may resume the case after the pause date, then re-evaluate the same sequential rule.

## Dunning profiles

Every stage setting comes from the invoice's dunning profile (#32). The first rule that finds an active profile decides: the profile chosen on the invoice, the categories of its products and services (a category counts through its nearest parent that a profile names), the customer's categories, the customer's type, then the default profile. When one rule finds several profiles, the most careful applies: one without automatic sending before one with it, then the lowest total fee. When the assignment cannot be read, the most careful of all profiles applies. The cron sends automatically only when the profile and the stage allow it.

## Late-payment interest

The profile holds the interest rule: none, a fixed rate, or the base rate of each day plus a surcharge in percentage points. Interest is counted per day on the open amount, from the day after the due date up to today, and shown in the tab, the email and the letter. A delivered notice books it in the ledger, where a new interest claim replaces the open one of the same case, so nothing is counted twice. The Dolibarr invoice stays as it is (#33).

## Handover

A case that is open can be handed over to debt collection or a lawyer, with a reason. It then rests in the state `handed_over`: the automation sends nothing more for that invoice, and its history stays as it is. The whole file goes into Dolibarr's own document store under `ecm/mahnwesen/<invoice>`: the invoice as it was sent, every dunning letter of a delivery that went out, a summary with the checksums of each file, the open claims and the history. No archive of its own is built, and nothing is generated again (#40).

## Events for other modules

`MAHNWESEN_NOTICE_SENT`, `MAHNWESEN_CASE_FINAL_STAGE`, `MAHNWESEN_CASE_PAUSED`, `MAHNWESEN_CASE_RESUMED`, `MAHNWESEN_CASE_REOPENED` and `MAHNWESEN_CASE_CLOSED` are ordinary Dolibarr triggers. Every event carries the contract: a stable event id, its version, entity, case and invoice, stage, profile code, the case revision and the time it happened. It never carries email texts, recipients, bank data, reasons or documents.

The note is written in the same transaction as the change, so there is no event without its change and no change without its event. Delivery happens after that transaction is committed, and only then: a receiver that fails leaves the event in the backlog with its attempt counted, and neither rolls back the change nor sends a notice twice. The same transition keeps its event id, so a receiver recognises a repeated delivery; delivery is at least once, and a receiver that arrives late reads the current state itself. An ambiguous send is not a `NOTICE_SENT`; only its controlled resolution is (#59).

## Payments and corrections

Dolibarr's own triggers for customer payments and invoice changes re-evaluate the case of every invoice they touch, with the same evaluation the daily run uses. A part payment lowers the open amount, full payment closes the case (or leaves it with open fees), and a cancelled payment opens it again; stages that were already sent stay sent and are not repeated. A failure of the re-evaluation never rolls back the payment: the daily run repairs the case. Dolibarr announces a payment that is being removed before it is gone, so what is open is only final afterwards; that case is noted (`recheck`) and the next Mahnwesen page or the daily run evaluates it, with the same evaluation as everything else (#36).

## Membership fees

An invoice counts as a membership fee only through Dolibarr's own link between the invoice and the subscription (`llx_element_element`, sourcetype `subscription`). A customer category, a note or the same email is not proof, so a sale to a member keeps its ordinary profile. A profile marked for membership fee invoices then applies; on an invoice that is both a fee and a sale, the most careful of the profiles that apply is used. Whether a membership is reviewed afterwards is the profile's final step; acting on it is #59 (#58).

## Fees and interest on their own invoice

The dunning tab offers to put the open fees and interest of a case on a new Dolibarr draft invoice, one line per claim, without VAT, naming the original invoice. The original invoice is never touched. The claims then point at that invoice and count as paid once it is paid, which the daily job records. What already went on such an invoice is not asked for again in later notices (#34).

## Dunning block

A pause belongs to one case. A dunning block lives in Dolibarr's own fields of the customer (all their invoices) or of one invoice: *Do not dun*, *Dunning block until* (last day, empty = without end) and a reason. While a block applies, no notice is sent by hand or automatically and no dunning PDF is made; the dry run names the block. The day after its last day it no longer applies. A block that cannot be read counts as a block.

## Invoice UI target

Show both:

- `Zeitlich fällig: 2. Mahnung`
- `Nächster Workflow-Schritt: 1. Mahnung`

Primary action:

`1. Mahnung vorbereiten`

The preview should expose recipient, subject, rendered HTML, dunning PDF and invoice attachment before send.


## Agenda projection

`llx_mahnwesen_history` remains the immutable workflow source of truth. Relevant history rows are additionally projected into Dolibarr `ActionComm` events linked to the customer invoice. The projection uses the history row id as an external id, so repeated synchronization does not create duplicate Agenda events.

This makes case creation, pause/resume, note changes and dunning send outcomes visible in the invoice's normal **Events/Agenda** tab without making Agenda the authority for workflow decisions.

## Composer revalidation

Before a manual email is sent, the module synchronizes the case and re-checks both the required stage and the remaining amount. If either changed while the composer was open, the send is refused and the user must review the newly rendered email/PDF again.

The final gate locks the case and revalidates entity, current invoice balance,
currency, fee, stage, cooldown, pause and selected recipient. It then reserves a
dedicated attempt before generating a unique PDF. Only the exact reserved body,
recipient, amounts and hashed attachments are handed to the mailer.

A send that certainly delivered nothing is recorded as `failed`: mail is
switched off (`MAIN_DISABLE_ALL_MAILS`), or Dolibarr's SMTP client (send mode
`smtps`) never got the server's go-ahead for the message data because the
connection, TLS, login, the sender or every recipient failed. A failed notice
can be sent again; the cron retries it up to the retry limit per case and stage.

Any other error after the mailer was invoked is treated as ambiguous, including
every failure with PHP `mail()` or Swift Mailer, which leave no protocol trace.
It blocks a retry until an operator checks the mail system and records either
confirmed delivery or permission to retry.

## One broken invoice

When a single invoice cannot be read or its case cannot be written, or a dated
pause cannot be lifted, the cron skips that invoice and goes on with the
others. The run ends as `warning`, its summary names the invoice, and the
Dolibarr cron job reports an error so it is noticed. Only a failure of a whole
step - reading the invoices, the case tables - stops delivery.


## Late-send spacing

The configured threshold differences are also used as a minimum interval after the previous enabled stage was actually completed. With thresholds `3 / 10 / 20 / 30`, the nominal gap from payment reminder to 1st dunning notice is seven days. If the reminder is sent late on day 14, the 1st dunning notice therefore becomes actionable no earlier than day 21 even though its absolute invoice threshold (day 10) already passed.

Effective stage due date:

```text
max(
  invoice_due_date + current_stage_threshold,
  previous_stage_completion + (current_threshold - previous_threshold)
)
```

This prevents rapid catch-up escalation for delayed/manual workflows and applies identically to cron sending.
