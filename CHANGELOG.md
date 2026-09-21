# Changelog

All notable changes to the Mahnwesen module. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/), with `-beta` marking pre-releases.
The section of a version is the text of its GitHub release.

## [Unreleased]

## [1.2.2] - 2026-09-21

Dunning letters that look like your invoices.

### Fixed

- The letter cut the email text after "Mit freundlichen Grüßen" and dropped
  the company name the starter templates put there. The letter now ends only
  where the template says so, with `<!--MAHNWESEN_PDF_END-->`; the text after
  it stays in the email (#25).

### Added

- The letterhead of Dolibarr's PDF setup (*PDF background*) is drawn on the
  dunning letter, as on the invoices, and the letter uses the font Dolibarr
  uses for the customer's language (#25).

## [1.2.1] - 2026-09-21

The code sorted by what it does. Nothing changes for users.

### Changed

- The two large classes are sorted by responsibility instead of by length:
  scanning, stages, workflow, cases, delivery attempts, fees, history, and
  automation for the manager; templates, recipients, documents, PDF and
  delivery for the notice service. Their methods and behaviour stay the same
  (#24).
- One check decides whether a user may see a customer's invoices; there were
  five copies (#24).
- The workflow rules - which stage the calendar reaches, which stage comes
  next, when it is due - live in a class without a database and have unit
  tests for their edge cases (#24, #29).

### Removed

- Seven methods nothing called any more (#24).

## [1.2.0] - 2026-09-21

The first release of milestone 1.2: the code is checked statically before
the larger rebuild.

### Fixed

- Small findings of the new static analysis: the dunning tab printed the
  empty return value of Dolibarr's banner function, and some documentation
  comments named the wrong parameters or types (#31).

## [1.1.4] - 2026-09-21

All settings of a stage in one place, and emails of any size.

### Changed

- The settings of a stage - days, business and private-person fee, payment
  period, automatic sending, template - live in the stage table only. The
  private-person fees and payment periods that earlier versions kept as
  separate settings move there when the module is activated after the update,
  and the old settings are removed (#20).
- The database is updated by `sql/update_1.1.4.sql`, which Dolibarr runs on
  every activation: two unused columns of the stage table are removed and
  timestamps follow every change (#20).

### Fixed

- An email over 60 KB, for example with a long footer or an embedded image,
  was refused. The stored copy of a notice now holds up to 15 MB (#20).

## [1.1.3] - 2026-09-21

The recipient from the customer's contact roles, and an invoice view that
speaks plainly.

### Added

- When an invoice has no billing contact, the customer's default billing
  contact for invoices is the recipient, before the company address (#22).

### Changed

- The invoice card has one dunning action instead of two; the PDF stays on
  the dunning tab (#56).
- The dunning tab and the composer name the next step in one phrase, for
  example "2. Mahnung (zeitlich wäre bereits die 3. Mahnung erreicht)", show
  the amount to pay with its parts only when a fee applies, and no longer show
  internal codes such as `TE_PRIVATE` (#56).
- Skipping a stage asks for confirmation with a reason in a dialog (#56).
- The hint that manual sending is off links administrators to the setting, and
  the minimum amount in the setup uses the user's number format (#56).

## [1.1.2] - 2026-09-21

A dry run you can trust, and help for operating automatic dunning.

### Fixed

- The dry run could show nothing ready while the cron then sent notices: it
  did not include the synchronisation, used the viewer's customers and private
  templates, and showed stages without automatic sending as blocked. It now
  takes the cron's own decision for every invoice, as the synchronisation
  would leave the cases - new cases, ended pauses - and changes nothing. It
  lists the invoices you may see and says in plain words why each one is sent,
  skipped or not due (#16).

### Added

- A right "Run the automatic dry run"; without it the dashboard offers no dry
  run and writes no run record (#16).
- An address in the automation setup that is told by email about every run
  that fails or ends with warnings (#21).
- On the delivery attempts page, the failed attempts of a run can be released
  in one step after a mail server outage (#21).
- The dashboard and the automation setup warn when Dolibarr's own payment
  reminder job is on, because customers would get both (#21).
- `scripts/release.py --commit` releases a merged version after a later pull
  request was merged before it was released (#70).

### Changed

- Automatic sending uses public email templates only, so the result does not
  depend on the user the cron runs as (#16).
- A missing sender address or a missing invoice PDF the template attaches now
  stops an automatic notice before a delivery attempt is made; the run ends
  as a warning that names the invoice (#16).

## [1.1.1] - 2026-09-17

Automatic dunning that keeps going when the mail server or one invoice fails.

### Fixed

- Every mail error blocked the notice until someone resolved it by hand, and
  the retry limit never applied. A send the mail server certainly never
  received - it was unreachable, refused the login, the sender or every
  recipient, or mail is switched off - is now recorded as failed: it can be
  sent again, and the cron retries it up to the retry limit. Errors after the
  server accepted the message still need a decision (#14).
- One invoice that could not be read or whose case could not be saved stopped
  all automatic dunning. The cron now skips it, sends the others, and ends the
  run as a warning that names the invoice (#15).

## [1.1.0] - 2026-09-17

Automatic dunning on the right day, and fees that stay right after a late
confirmation.

### Fixed

- The spacing between two stages counted from the time of day the previous
  notice was sent. A reminder sent at 14:00 made the next stage due at 14:00
  seven days later, so the nightly cron sent it a day late. The spacing now
  counts whole days (#18).
- A stage could be skipped while its delivery attempt was unresolved. If the
  notice had in fact arrived and was confirmed later, its lower fee replaced
  the fee of the higher stage sent in between. Skipping now waits until the
  attempt is resolved, and a late confirmation never replaces the fee of a
  higher stage; the case keeps the date of its latest notice (#17).

## [1.0.5] - 2026-09-16

A payment deadline in dunning notices.

### Added

- A payment period per stage under *Setup > Stages & fees* (0 = none, the
  default, so nothing changes until you set one). A notice then names the date
  by which to pay: in the PDF below the total, and in email templates as
  `__MAHNWESEN_PAYMENT_DEADLINE__` (with `__MAHNWESEN_PAYMENT_DAYS__` for the
  number of days). Until now a template could only print today's date (#64).
- The history of a sent notice records its payment deadline (#64).

### Changed

- After a notice with a payment deadline, the next stage becomes due no
  earlier than the day after that deadline, and `__MAHNWESEN_NEXT_STAGE_DATE__`
  says so. The next notice no longer arrives while the customer still has time
  to pay (#64).

## [1.0.4] - 2026-09-16

Email templates as you would expect them, and updates that keep manual sending.

### Fixed

- The composer's template list showed the English starter template as just
  "English": Dolibarr translates the part of a template name in parentheses.
  Starter templates are now called "Mahnwesen - 2. Mahnung - English";
  existing ones are renamed when the module is activated or starter templates
  are created (#53).
- A template you wrote yourself lost against the module's starter template.
  Now your own template of a stage wins, as long as its language fits the
  customer or it has no language (#53).
- Every activation created English starter templates, even when nobody writes
  English. Starter templates are now created only for German and English as
  far as the company or a customer uses them, and not for a stage that already
  has a template in that language (#53).
- Disabling and enabling the module - the usual update step - switched manual
  sending off. It now keeps its setting; automatic sending is still switched
  off, and the setup says so (#54).

### Added

- The templates tab of the setup lets you fix the template of each stage, or
  leave it on automatic (#53).

## [1.0.3] - 2026-09-16

Clean file names for dunning PDFs, and delivery evidence kept apart.

### Fixed

- Dunning PDFs in the invoice documents and in the email carried a timestamp
  or an attempt id (`IN2607-0052_2.Mahnung_20260916_143901.pdf`). They are
  now called like Dolibarr's own documents, `IN2607-0052_2.Mahnung.pdf`, one
  per stage, replaced when generated again (#52).

### Changed

- The exact files of every delivery - dunning PDF, invoice PDF and extra
  attachments - are kept in the module's folder `mahnwesen/attempts/<id>/`
  instead of the invoice documents, where they could be deleted with the
  invoice's other files. The delivery attempts page offers them for download
  to users who may see the invoice. Files of earlier attempts stay where they
  are and remain downloadable (#19).

## [1.0.2] - 2026-09-16

Small fixes found while testing 1.0.1.

### Fixed

- The dashboard and its footer named version 0.5.3 (#9).
- The setting "attach original invoice PDF by default" had no effect; it is
  gone, and the setup explains that the stage's email template decides (#10).
- Skipping a stage, resolving a delivery attempt and settling a fee said only
  that they failed; they now name the reason (#11).
- Saving the automation settings while automatic sending was already on asked
  for the risk confirmation again; it is needed only to switch it on (#12).
- The workflow and architecture documents and the German README described
  stage skipping and the rule table as future or reserved (#13).

## [1.0.1] - 2026-09-16

Bugfix release. The dunning tab, the composer and the dunning buttons work again
for everyone allowed to use them, and from now on every change is released as a
version of its own, built locally and verified by GitHub.

### Fixed

- The invoice's Mahnwesen tab, the dunning composer, pausing from the dashboard,
  resolving delivery attempts and settling fees were denied for every user,
  administrators included: the access check asked for a permission Dolibarr
  does not have (#44).
- The dunning buttons never appeared on the invoice card; the hook now prints
  them the way Dolibarr expects (#45).
- Users other than administrators could not open the preview PDF; the composer
  now serves it behind its own access checks (#5).
- Unticking the invoice PDF in the composer was ignored and the PDF was attached
  anyway (#6).
- An indefinite pause was shown with the due date of the next stage as its end;
  closing a case now also ends its pause (#7).
- The invoice PDF was looked up in the wrong directory, so the composer's view
  link failed and a differently named invoice PDF counted as missing (#8).

### Added

- Dolibarr 24.0 in the compatibility checks (#3).
- Runtime checks that run the module in real Dolibarr 21, 22, 23 and 24
  installations: activation, access rules, preview, a real send with the
  attachment hashes compared against the delivered bytes, and automatic
  sending (#4).

### Changed

- Every merged pull request that changes the module is released as
  "Mahnwesen vX.Y.Z". The package is now called `module_mahnwesen-x.y.z.zip`,
  is built reproducibly from the tagged commit, and GitHub rebuilds it to prove
  the published file byte for byte (#51).

## [1.0.0] - 2026-08-27

- Replaced the custom mail-form imitation with Dolibarr's native `FormMail` component for templates, sender profiles, recipients, CC/BCC, delivery receipts and HTML editing.
- Added a combined rendered-email and embedded PDF preview plus mandatory, invoice and user-uploaded document controls.
- Snapshotted every transmitted attachment and stored its role, name, MIME type, size and SHA-256 hash as delivery evidence.
- Added persistent cron and dry-run history with scanned, synchronized, attempted, sent, skipped and failed counters.
- Added configurable automatic retry, per-customer/run and manual attachment count/size limits.
- Prevented overlapping cron deliveries with an expiring entity-scoped run lock.
- Extended the operations page with full attempt metadata, document evidence, automation history and cross-invoice immutable workflow history.
- Kept automatic delivery off by default and retained atomic balance, fee, stage, cooldown, recipient and duplicate revalidation before SMTP.
- Added native FormMail/upload API contracts to the Dolibarr 21/22/23 compatibility checks.
- Updated installation, upgrade, architecture, security and release documentation for the first stable line.

## [0.6.0]

- Enforced Dolibarr invoice read/restricted-area permissions and active-entity isolation.
- Added one atomic send gate that revalidates current balance, fee, stage, cooldown, pause, recipient and duplicate state under a case lock.
- Added dedicated delivery-attempt records with exact body/configuration snapshots, unique PDFs, SHA-256 attachment hashes and permission-checked recovery with a safety delay for in-flight SMTP outcomes.
- Kept workflow history append-only; attempt state is maintained in a separate operational table.
- Added a separate pause lifecycle table and a fee subledger with paid/waived settlement actions.
- Made recipient lookup fail closed, filtered inactive contacts and used the selected contact in the PDF address.
- Blocked foreign-currency invoices until a correct multicurrency workflow is implemented.
- Limited automatic attempts rather than only successes and added bounded automatic retries.
- Added English starter templates and language-family-aware selection before cross-language defaults.
- Added visible PDF preview/draft actions and hardened inline JSON against script-context injection.
- Added policy tests, stronger language/security contracts, real Dolibarr 21/22/23 source/API checks and release tag/version validation.
- Raised the declared PHP minimum to 7.4 and set all new default dunning fees to zero.

## [0.5.4]

- Cleaned the dunning PDF total area: one full-width closing rule and a borderless, right-aligned total replace the previous visually half-open amount box.
- Kept generated notices in the Sponge-style one-page layout where content permits.
- Release ZIP version now follows module version `0.5.4`.

## [0.5.3]

- Completed the repository source baseline used for the first GitHub-managed module build.
- Added main-branch CI, Dolibarr compatibility checks and automatic installable ZIP artifacts.
- Added tag-driven GitHub release ZIP generation.

## [0.5.2]

- Kept normal Sponge-style dunning letters on one page by reserving footer space and disabling TCPDF auto page-break while drawing the standard footer.
- Renamed generated invoice-linked dunning PDFs to stable, human-readable names such as `IN2607-0054_Zahlungserinnerung.pdf`, `IN2607-0054_1.Mahnung.pdf`, etc.
- Polished the email composer to more closely match Dolibarr's regular invoice send form, including a centered native-template selector with an explicit Apply action, narrower labels, full-width HTML editor and normal Cancel action.
- Improved sender-profile labels so the company/profile name is shown together with the email address.
- Removed the duplicate visible dunning-history section when Dolibarr Agenda is enabled; the immutable history remains in module tables and is visible in the invoice Agenda.
- Reworked dunning PDF generation to follow Dolibarr's Sponge invoice visual structure instead of the former independent/Cyan-inspired layout.
- Final dunning PDFs can be generated directly from the invoice tab and are stored in the invoice document directory without replacing the invoice main document.
- Added immutable `document_generated` history entries mirrored to the invoice Agenda.
- Dashboard KPIs and overdue list now prioritize the next required workflow step while retaining the calendar threshold as secondary information.
- Removed automatic PDF generation on composer load; previews are explicit and template switching updates the HTML composer client-side without a full page reload.
- Updated the composer to use Dolibarr-like editor sizing and added a direct "generate PDF with invoice" action.
- Repository made version-agnostic in naming/documentation.
- Dolibarr 21, 22 and 23 defined as the active compatibility target matrix; module minimum target lowered to 21 pending expanded runtime smoke tests.
- Added CI/static compatibility checks, release tooling, architecture/security/workflow documentation and issue templates.
- Implemented strict sequential escalation: the calendar threshold and the next permitted workflow stage are separate, and later stages cannot bypass an earlier due stage that was not successfully sent (or explicitly audited as skipped in a future waiver flow).
- Manual and automatic sending now use the same sequential stage guard.
- Preserved configured inter-stage spacing after late sends, preventing rapid catch-up escalation on consecutive cron runs.
- Added a dynamic dunning action to Dolibarr's regular customer-invoice card through the `invoicecard` hook.
- Redesigned the Mahnwesen invoice tab with a compact four-column status table and pencil-style inline editing for internal note and pause/follow-up.
- Redesigned the dunning composer around Dolibarr's normal email-send layout: template selection, sender profiles, recipient, CC/BCC, subject, attachments, HTML editor, PDF preview and explicit send confirmation.
- Added pre-send revalidation of workflow stage and remaining amount so stale email/PDF content is never sent after the invoice state changed.
- Mirrored immutable Mahnwesen history idempotently into the invoice's normal Dolibarr Events/Agenda via `ActionComm`; the module history remains the workflow source of truth.

## [0.4.2]

- Fixed Dolibarr 22 native email-template hook registration by using the direct `emailtemplates` hook context format.
- Automatically creates one editable starter template per dunning stage when missing; no separate creation button.
- Added contextual Mahnwesen variable help on the native Dolibarr email-template page and in module settings.
- Removed the shortcut button to Dolibarr email templates; settings now only document the navigation path.
- Added a Cyan-inspired dunning PDF using Dolibarr PDF margins, company logo, address blocks and a financial summary.
- Kept private-person dunning fees safe at zero by default while remaining configurable per stage.

## [0.4.1]

- Replaced the former single Mahnwesen email-template type with four real native Dolibarr types: payment reminder, 1st, 2nd and 3rd dunning notice.
- Added explicit/self-healing registration of the `emailtemplates` hook on module activation for Dolibarr 22.
- Native Dolibarr email templates are now the primary template source; the module automatically resolves the matching public template per dunning stage.
- Added separate configurable dunning-fee columns per stage for private persons and businesses.
- Added `TE_PRIVATE`-aware fee selection while unknown/special types remain fee-free by default.
- Renamed stage 4 to `3. Mahnung` consistently.
- Redesigned the invoice Mahnwesen tab using Dolibarr-style field tables, textareas, section headers and a grouped action bar.
- Separated persistent internal notes from pause/resume history reasons in the UI.
- Added Redirect-after-POST to prevent browser refreshes from repeating write actions/history events.
- Retained timed/indefinite pause, automatic resume, controlled automatic sending and duplicate-send safeguards.
- Invoices and invoice amounts are never modified by dunning fees.

## [0.4.0]

- Added native HTML email template integration, configurable dunning fees, timed pauses and controlled automatic sending.
- Added customer master-data classification and separate module-only dunning totals.

## [0.3.0]

- Added manual dunning notice preview/send workflow.
- Added BILLING-contact-first recipient resolution with third-party fallback.
- Added dunning PDF generation and optional existing invoice-PDF attachment.
- Added explicit send confirmation, duplicate-send guard per dunning level, and send audit history.
- Separated persistent internal case note from pause/resume reasons.

## [0.2.2]

- Fixed synchronization success-message crash on Dolibarr 22 / PHP 8.

## [0.2.1]

- Added persistent synchronization of dunning cases, case lifecycle, history and pause/resume.
- Added a Mahnwesen tab to customer invoices.

## [0.1.1]

- Added detailed scan diagnostics and optional deposit invoice inclusion.

## [0.1.0]

- Initial read-only overdue invoice scan for Dolibarr 22.

[Unreleased]: https://github.com/Tabsi1998/dolibarr-mahnwesen/compare/v1.2.2...HEAD
[1.2.2]: https://github.com/Tabsi1998/dolibarr-mahnwesen/releases/tag/v1.2.2
[1.2.1]: https://github.com/Tabsi1998/dolibarr-mahnwesen/releases/tag/v1.2.1
[1.2.0]: https://github.com/Tabsi1998/dolibarr-mahnwesen/releases/tag/v1.2.0
[1.1.4]: https://github.com/Tabsi1998/dolibarr-mahnwesen/releases/tag/v1.1.4
[1.1.3]: https://github.com/Tabsi1998/dolibarr-mahnwesen/releases/tag/v1.1.3
[1.1.2]: https://github.com/Tabsi1998/dolibarr-mahnwesen/releases/tag/v1.1.2
[1.1.1]: https://github.com/Tabsi1998/dolibarr-mahnwesen/releases/tag/v1.1.1
[1.1.0]: https://github.com/Tabsi1998/dolibarr-mahnwesen/releases/tag/v1.1.0
[1.0.5]: https://github.com/Tabsi1998/dolibarr-mahnwesen/releases/tag/v1.0.5
[1.0.4]: https://github.com/Tabsi1998/dolibarr-mahnwesen/releases/tag/v1.0.4
[1.0.3]: https://github.com/Tabsi1998/dolibarr-mahnwesen/releases/tag/v1.0.3
[1.0.2]: https://github.com/Tabsi1998/dolibarr-mahnwesen/releases/tag/v1.0.2
[1.0.1]: https://github.com/Tabsi1998/dolibarr-mahnwesen/releases/tag/v1.0.1
[1.0.0]: https://github.com/Tabsi1998/dolibarr-mahnwesen/releases/tag/v1.0.0
