# Changelog

## Unreleased

## 1.0.0

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

## 0.6.0

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

## 0.5.4 (release candidate)

- Cleaned the dunning PDF total area: one full-width closing rule and a borderless, right-aligned total replace the previous visually half-open amount box.
- Kept generated notices in the Sponge-style one-page layout where content permits.
- Release ZIP version now follows module version `0.5.4`.

## 0.5.3

- Completed the repository source baseline used for the first GitHub-managed module build.
- Added main-branch CI, Dolibarr compatibility checks and automatic installable ZIP artifacts.
- Added tag-driven GitHub release ZIP generation.

## 0.5.2 (test build / intended `v0.5.2-rc.1`)

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

## 0.4.2

- Fixed Dolibarr 22 native email-template hook registration by using the direct `emailtemplates` hook context format.
- Automatically creates one editable starter template per dunning stage when missing; no separate creation button.
- Added contextual Mahnwesen variable help on the native Dolibarr email-template page and in module settings.
- Removed the shortcut button to Dolibarr email templates; settings now only document the navigation path.
- Added a Cyan-inspired dunning PDF using Dolibarr PDF margins, company logo, address blocks and a financial summary.
- Kept private-person dunning fees safe at zero by default while remaining configurable per stage.

## 0.4.1

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

## 0.4.0

- Added native HTML email template integration, configurable dunning fees, timed pauses and controlled automatic sending.
- Added customer master-data classification and separate module-only dunning totals.

## 0.3.0

- Added manual dunning notice preview/send workflow.
- Added BILLING-contact-first recipient resolution with third-party fallback.
- Added dunning PDF generation and optional existing invoice-PDF attachment.
- Added explicit send confirmation, duplicate-send guard per dunning level, and send audit history.
- Separated persistent internal case note from pause/resume reasons.

## 0.2.2

- Fixed synchronization success-message crash on Dolibarr 22 / PHP 8.

## 0.2.1

- Added persistent synchronization of dunning cases, case lifecycle, history and pause/resume.
- Added a Mahnwesen tab to customer invoices.

## 0.1.1

- Added detailed scan diagnostics and optional deposit invoice inclusion.

## 0.1.0

- Initial read-only overdue invoice scan for Dolibarr 22.
