# Roadmap

This roadmap distinguishes implemented behavior from planned workflow hardening. Items are intentionally not presented as finished until they are tested.

## Current baseline - 0.4.x

- [x] overdue customer-invoice scanner
- [x] remaining-balance calculation using Dolibarr invoice APIs
- [x] persistent dunning case per invoice
- [x] case/history tables owned by the module
- [x] pause / resume and dated pause concept
- [x] invoice tab integration without core edits
- [x] customer type classification including `TE_PRIVATE`
- [x] configurable private/business dunning fees
- [x] native Dolibarr email-template integration
- [x] dunning-specific template variables
- [x] dunning PDF generation aligned to Dolibarr Sponge
- [x] BILLING-contact-first recipient resolution
- [x] manual send permission/config gates
- [x] duplicate/pending send reservation concept
- [x] automatic-send configuration foundation

## Hardened workflow baseline - 0.6.x

### Strict stage sequence

- [x] derive `next_required_level` from immutable send history
- [x] distinguish `time_eligible_level` from `workflow_required_level`
- [x] never auto-advance past an unsent required stage
- [x] preserve configured spacing after a late previous-stage send
- [x] allow an authorized explicit `waive/skip` action with mandatory reason
- [x] log sent / failed / pending decisions immutably; waiver/skip audit remains planned
- [x] make manual and automatic sending use the exact same eligibility service

Example:

- invoice is 25 days overdue
- time eligibility = 2nd dunning notice
- only payment reminder has been sent
- next workflow action = **1st dunning notice**, not 2nd

### Dynamic invoice action

- [x] one primary context-sensitive action on invoice tab/card
- [x] labels such as `Payment reminder`, `1st dunning notice`, etc.
- [x] disabled state with explanation when paused or blocked
- [x] show current time-eligible stage and next required workflow stage separately

### Dolibarr-like send preview

- [x] recipient selector / resolved BILLING contact
- [x] sender profile selection using Dolibarr email sender profiles
- [x] subject
- [x] rendered HTML body
- [x] generated dunning PDF preview
- [x] optional invoice PDF attachment preview
- [x] explicit send confirmation
- [x] post-send history record and invoice Agenda projection

### Automation

- [x] automatic sending obeys strict stage sequence
- [x] global enable switch remains OFF by default
- [x] per-stage automatic-send switch
- [x] per-run send limit
- [x] no automatic send for ambiguous recipient
- [x] no automatic send while paused
- [x] auto-resume dated pauses before eligibility evaluation
- [x] dry-run / simulation report for automatic decisions

## Compatibility and quality

- [ ] runtime smoke test on Dolibarr 21
- [x] runtime use on Dolibarr 22
- [ ] runtime smoke test on Dolibarr 23
- [ ] evaluate Dolibarr 20 as best-effort compatibility target
- [x] add dependency-free policy tests and static security contracts
- [ ] add database migration tests
- [ ] add full Dolibarr container/database integration tests
- [ ] add mail rendering snapshot tests
- [ ] add PDF smoke tests

## Later ideas

- configurable default interest rules without altering invoices
- country/legal profiles separated from technical defaults
- bulk approval screen
- customer-specific dunning policy override
- debtor exclusion / dispute flag
- export/reporting for outstanding dunning cases
- richer Agenda/email-document links and optional dedicated dunning event type
- [x] language-safe template selection based on customer language/family
