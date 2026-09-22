# Dolibarr Mahnwesen

A custom Dolibarr module for controlled dunning workflows on overdue customer invoices.

> **Status:** stable line `1.0.x`. Automated runtime checks install the module into Dolibarr 21.0.4, 22.0.5, 23.0.4 and 24.0.1 and exercise activation, access rules, a real send and automatic sending; CI checks the Dolibarr 21-24 APIs the module uses. A staging smoke test with your own mail server remains required for each production environment.

## Goals

- no Dolibarr core modifications
- persistent dunning cases and audit history
- configurable dunning stages and fees
- dunning profiles per kind of claim: stages, fees, payment periods, automatic sending and the final step, chosen by the invoice, the categories of its products, the customer's categories or type
- native Dolibarr HTML email templates
- Sponge-aligned dunning PDF
- manual and controlled automatic sending
- pause indefinitely or until a date
- dunning block on a customer or an invoice, in Dolibarr's own fields, with reason and end date
- strict duplicate-send protection
- multi-version compatibility with maintained Dolibarr releases

## Current workflow

The module scans validated/open customer invoices with a due date in the past, calculates the time-based dunning stage, and stores the workflow state in its own tables. It does **not** alter the original invoice amount or status.

The current four-stage default is:

| Stage | Default threshold |
|---|---:|
| Payment reminder | 3 days overdue |
| 1st dunning notice | 10 days overdue |
| 2nd dunning notice | 20 days overdue |
| 3rd dunning notice | 30 days overdue |

All thresholds are configurable.

## Sequential workflow rule

Time alone does not skip a required stage. The stable workflow uses this sequential escalation model:

`Payment reminder -> 1st dunning notice -> 2nd dunning notice -> 3rd dunning notice`

If, for example, the 1st dunning notice has never actually been sent, a later date threshold must **not** silently jump to the 2nd notice. A stage advances after the required previous stage is successfully sent. The architecture also reserves an audited explicit waiver/skip state for a future authorized UI. Automatic sending follows the same eligibility guard as manual sending. Configured spacing between stages is also preserved after a late send, preventing several escalation notices from being sent on consecutive cron runs merely because their original invoice-date thresholds already passed.

See [docs/WORKFLOW.md](docs/WORKFLOW.md) and [ROADMAP.md](ROADMAP.md).


## Invoice integration

The module adds a context-sensitive action to the normal customer-invoice card, for example `Prepare payment reminder` or `Prepare 1st dunning notice`. The action is based on the **next required sequential workflow stage**, not merely the highest time-eligible stage.

The dunning composer uses Dolibarr's native `FormMail` component for templates, sender profiles, recipient, CC/BCC, subject, delivery receipt and HTML editing. It adds the mandatory dunning PDF, optional invoice PDF, uploaded files and a combined email/document preview. Immediately before an irreversible send, the case, recipient, stage, amounts and attachments are revalidated.

## Events / Agenda

`llx_mahnwesen_history` remains the append-only workflow source of truth. Mutable delivery state lives separately in `llx_mahnwesen_attempt`; PDF/body/recipient/amount snapshots and SHA-256 hashes make a delivery auditable. Ambiguous SMTP outcomes block retries until an operator resolves them on the **Delivery attempts** page.

Dunning fees are tracked in a module-owned subledger after a successful notice. They do not modify the Dolibarr invoice or create an accounting entry and must be marked paid or waived explicitly.

Every cron and dry run is stored in an automation-run history. Automatic sending is off by default and requires both the global and per-stage switches. Per-run, per-customer and per-stage failure limits constrain delivery; a send the mail server never received is retried up to the failure limit, while ambiguous SMTP outcomes require operator resolution before any retry. One broken invoice is skipped and reported instead of stopping the run. The dry run takes exactly the cron's decision, an optional address is told about runs with problems, and the failed attempts of a run can be released in one step.

## Email templates

Mahnwesen integrates with Dolibarr's native **Email setup -> Email templates** page and provides dedicated template types for the four dunning stages. Templates can use Dolibarr's normal HTML editor plus Mahnwesen variables.

See [docs/VARIABLES.md](docs/VARIABLES.md).

## Requirements

| | Minimum | Tested |
| --- | --- | --- |
| Dolibarr | 21.0 | 21.0.4, 22.0.5, 23.0.4, 24.0.1 |
| PHP | 7.4 | 7.4, 8.1, 8.2, 8.3, 8.4 |
| Dolibarr modules | Invoices | Agenda for the invoice history, Cron for automatic sending |

## Installation and updates

Every change is published as a release "Mahnwesen vX.Y.Z" with patch notes on the [releases page](https://github.com/Tabsi1998/dolibarr-mahnwesen/releases).

1. Download `module_mahnwesen-x.y.z.zip`. Do not rename it: Dolibarr accepts only this name and derives the module folder from it.
2. In Dolibarr open *Home > Setup > Modules > Deploy an external module* and upload the ZIP. An existing `custom/mahnwesen` folder is replaced.
3. Enable **Mahnwesen** in the module list - for an update, disable and enable it once. Cases, history and settings are kept.
4. Switch on automatic sending only after a test on a staging system.

With Git, clone the repository as `htdocs/custom/mahnwesen` and update with `git pull`; uploading a ZIP over that folder would delete its `.git` folder.

## Compatibility

The project is intentionally not branded for one Dolibarr major version. The compatibility policy and current verification status are documented in [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md).

## Safety model

Mahnwesen writes its workflow state only to module-owned tables. Sending is gated by permissions, configuration, recipient validation, stage guards, and duplicate-send protection. See [SECURITY.md](SECURITY.md).

## Project documentation

- [German README](README-DE.md)
- [Roadmap](ROADMAP.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Workflow / sequential escalation](docs/WORKFLOW.md)
- [Compatibility](docs/COMPATIBILITY.md)
- [Release strategy](docs/RELEASES.md)
- [Operations / upgrade runbook](docs/OPERATIONS.md)
- [Template variables](docs/VARIABLES.md)
- [Security](SECURITY.md)
- [Changelog](CHANGELOG.md)
- [Contributing](CONTRIBUTING.md)

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
