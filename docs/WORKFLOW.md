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
