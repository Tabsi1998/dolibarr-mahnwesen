# Operations and upgrade runbook

## Upgrade to 1.0.0

1. Back up the Dolibarr database and document directory.
2. Disable automatic sending before replacing module files.
3. Install the new module files.
4. Disable and re-enable Mahnwesen once. Dolibarr creates the new
   `mahnwesen_attempt`, `mahnwesen_attempt_file`, `mahnwesen_pause`,
   `mahnwesen_fee` and `mahnwesen_run` tables; existing cases, rules and
   history remain in place.
5. Open module setup and create missing DE/EN starter templates.
6. Run **Synchronize cases**, then **Automation dry run**. The dry run takes the cron's own decision for every invoice as if the cron ran now, including dated pauses that have ended and invoices without a case yet, and changes nothing. It needs the right *Run the automatic dry run*.
7. Test one manual notice with a non-production recipient and inspect the PDF,
   attachment hashes, Agenda event and delivery-attempt record.
8. Enable per-stage automation only after the staging result is accepted.

If the storage check reports a missing table, do not send mail. Repeat the
module disable/enable step and inspect the Dolibarr database/module logs.

## Daily operation

- Review **Mahnwesen > Delivery attempts** for `reserved`, `sending`,
  `ambiguous` or repeatedly failed attempts. After a mail server outage, allow
  the failed attempts again once it works; the cron stops at the retry limit.
- A run with the status `warning` skipped the invoices its summary names; fix
  them - the others were dunned.
- Never choose **Confirm as delivered** without checking the outgoing mailbox
  or SMTP server.
- `reserved`/`sending` attempts can only be recovered after a 15-minute safety
  delay; `ambiguous` and failed attempts are available immediately.
- Resolve open fee claims as paid or waived with a reason.
- Use dated pauses for payment promises and indefinite pauses for disputes.
- A dispute or an agreement that covers all invoices of a customer belongs in the customer's *Do not dun* field, with a reason and, if it ends, a last day. On one invoice the same fields block just that invoice. Blocked cases show under *With dunning block* on the dashboard.
- Run the dry-run report after template, sender, stage, entity or fee changes.
- Set *Notify about problems* in the automation setup: a run that fails or ends with warnings then sends one email to that address.
- Automatic sending uses public email templates only; a private template of the user the cron runs as is ignored.
- Keep Dolibarr's own payment reminder job (`sendEmailsRemindersOnInvoiceDueDate`) off; the dashboard and the setup warn when it is on.
- Overlapping cron delivery is blocked per entity; a lock left by a hard crash
  is marked failed and released after six hours.

## Backup and rollback

Disabling the module does not remove business tables. A code rollback to a
pre-0.6 version must keep automatic sending disabled because old code does not
understand the new attempt/recovery protocol. Restore both database and
document-directory backups together if a full rollback is required; PDF hashes
refer to the exact files present at send time.

## Release smoke test

- install/upgrade succeeds on a database copy;
- invoice users cannot access invoices outside their normal Dolibarr scope;
- foreign-currency invoice is blocked;
- inactive/ambiguous BILLING contacts block automation;
- a partial payment invalidates an open composer;
- concurrent submits produce one attempt only;
- missing invoice PDF blocks delivery when attachment is required;
- ambiguous attempt blocks retry until operator resolution;
- sent PDF address matches the selected recipient contact;
- automatic attempt count never exceeds the configured run limit;
- one customer never exceeds the configured per-run customer limit;
- additional attachments are snapshotted and listed with matching hashes;
- cron and dry-run results appear in automation history.
