# Dolibarr Mahnwesen

A custom Dolibarr module for controlled dunning workflows on overdue customer invoices.

> **Status:** hardened `0.6.x` development line. Dolibarr 22 has been runtime-tested. CI downloads and verifies the real Dolibarr 21/22/23 source APIs; full installation smoke tests on 21 and 23 remain release gates.

## Goals

- no Dolibarr core modifications
- persistent dunning cases and audit history
- configurable dunning stages and fees
- customer-type-aware fee rules (private person vs. business)
- native Dolibarr HTML email templates
- Sponge-aligned dunning PDF
- manual and controlled automatic sending
- pause indefinitely or until a date
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

Time alone does not skip a required stage. The current development branch uses this sequential escalation model:

`Payment reminder -> 1st dunning notice -> 2nd dunning notice -> 3rd dunning notice`

If, for example, the 1st dunning notice has never actually been sent, a later date threshold must **not** silently jump to the 2nd notice. A stage advances after the required previous stage is successfully sent. The architecture also reserves an audited explicit waiver/skip state for a future authorized UI. Automatic sending follows the same eligibility guard as manual sending. Configured spacing between stages is also preserved after a late send, preventing several escalation notices from being sent on consecutive cron runs merely because their original invoice-date thresholds already passed.

See [docs/WORKFLOW.md](docs/WORKFLOW.md) and [ROADMAP.md](ROADMAP.md).


## Invoice integration

The current development branch adds a context-sensitive action to the normal customer-invoice card, for example `Prepare payment reminder` or `Prepare 1st dunning notice`. The action is based on the **next required sequential workflow stage**, not merely the highest time-eligible stage.

The dunning composer follows Dolibarr's normal mail workflow: template selection, sender profile, recipient, CC/BCC, subject, HTML body, generated dunning PDF, optional invoice PDF, preview and explicit send confirmation. Immediately before an irreversible send, the case is synchronized and the required stage and remaining balance are revalidated.

## Events / Agenda

`llx_mahnwesen_history` remains the append-only workflow source of truth. Mutable delivery state lives separately in `llx_mahnwesen_attempt`; PDF/body/recipient/amount snapshots and SHA-256 hashes make a delivery auditable. Ambiguous SMTP outcomes block retries until an operator resolves them on the **Delivery attempts** page.

Dunning fees are tracked in a module-owned subledger after a successful notice. They do not modify the Dolibarr invoice or create an accounting entry and must be marked paid or waived explicitly.

## Email templates

Mahnwesen integrates with Dolibarr's native **Email setup -> Email templates** page and provides dedicated template types for the four dunning stages. Templates can use Dolibarr's normal HTML editor plus Mahnwesen variables.

See [docs/VARIABLES.md](docs/VARIABLES.md).

## Installation

For a Git checkout, clone the repository into Dolibarr's custom directory using the directory name `mahnwesen`:

```bash
git clone https://github.com/Tabsi1998/dolibarr-mahnwesen.git htdocs/custom/mahnwesen
```

For a release ZIP, upload `mahnwesen-x.y.z.zip` through Dolibarr's external module installer.

After upgrading to `0.6.x`, disable and re-enable the module once. This creates the new attempt, pause and fee-ledger tables; existing cases/history are retained. Keep automatic sending disabled until a staging smoke test has completed.

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
