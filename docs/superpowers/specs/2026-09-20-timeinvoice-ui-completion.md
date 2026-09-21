# TimeInvoice UI Completion — Design Spec

**Date:** 2026-09-20
**Author:** Carmelo Santana (decisions) / Claude (transcription)
**Status:** Settled — all open questions closed in the 2026-09-20 grilling session
**Plugin:** TimeInvoice (Kanboard 1.2.47, PHP >= 8.4)
**Current version:** 1.1.0 → targets 1.2.0 and 1.3.0

---

## Problem

TimeInvoice generates correct PDF invoices from TimeReport hours, but the controls
to *drive* it are missing, orphaned, or dishonest. A user cannot find where to
create an invoice, cannot set a rate per project, cannot set billing terms on an
invoice, and cannot look at an invoice without downloading a file. One button
claims to send email and does not.

The working hypothesis going in was that the features exist but are gated behind
AI/API availability. **That is false.** Exactly one feature is AI-gated (the cover
note). Everything else listed below is genuinely unbuilt.

### Audit findings (verified against source, 2026-09-20)

| # | Finding | Evidence |
|---|---------|----------|
| A1 | The cross-project invoice list has **no create button and no project picker**. The button is gated on `if ($project)` and `list()` always passes `'project' => null`. | `Template/invoice/list.php:7`, `Controller/InvoiceController.php:59` |
| A2 | The admin settings page is **orphaned** — routed at `timeinvoice/settings` but linked from nowhere. TimeInvoice is the only plugin in the suite with admin settings that does not attach `template:config:sidebar`. | `Plugin.php:27-28`; cf. `AiConnector/Plugin.php:32-34` |
| A3 | The settings page renders with `layout->app` instead of `layout->config`, so the config sidebar disappears while you are in settings. Its admin gate is a silent redirect, not `AccessForbiddenException`. | `Controller/SettingsController.php:26-34` |
| A4 | `projectDefaults()` reads `timeinvoice:defaults` from project metadata and `freezeSnapshot()` honours it — but **nothing ever writes that key**. There is no project settings UI. | `Controller/InvoiceController.php:99`, `:187-196` |
| A5 | **Per-user rates do not exist** anywhere — not in the form, model, snapshot or PDF. Kanboard core has no `hourly_rate` column and TimeReport stores no rate data. Every rate layer must be built here. | `kanboard-1.2.47/app/Schema/Sql/mysql.sql:733-761` |
| A6 | `terms_days` is global-only and appears on no form. The code documents the gap in a comment. | `Controller/InvoiceController.php:187-190` |
| A7 | **`send()` sends no email.** It freezes a snapshot, assigns a number and sets `status='sent'`. `client_email` is collected, stored, printed — and never used. | `Controller/InvoiceController.php:241-259` |
| A8 | **There is no preview and no detail page.** `pdf()` hardcodes `Content-Disposition: attachment`, so every look at an invoice is a file download. Actions live in a cramped table cell. | `Controller/InvoiceController.php:298` |
| A9 | The draft form asks for an hourly rate with **no indication of how many hours are in the range**. No subtotal is visible until you save, download and open a PDF. | `Template/invoice/form.php` |
| A10 | **TimeInvoice bills only the requester's own hours.** `report()` takes 8 params; params 7-8 (`$subjectUserIds`, `$allUsers`) default to self-only. TimeInvoice calls it positionally with 6. On any multi-person project the invoice silently under-bills. | `Controller/InvoiceController.php:209-213`, `Model/CoverNoteGenerator.php:34`, `TimeReport/Model/TimeReportModel.php:540` |
| A11 | `buildDraftFromRequest()` reads `currency_code`/`currency_symbol` from the request, but the form never posts them — so **every draft persists `USD`/`$` regardless of settings**. `freezeSnapshot()` then ignores the stored values, which is why the bug is invisible. | `Controller/InvoiceController.php:155-165`, `:193` |

### Constraints discovered

| Constraint | Consequence |
|---|---|
| **Kanboard's mail seam cannot send attachments.** `ClientInterface::sendEmail()` takes six scalar strings; no transport implements `attach()`. SwiftMailer is bundled but unreachable through the seam. | Emailing a PDF invoice is not possible without bypassing core. |
| **Mail failures are silent.** `Transport/Mail.php:98-100` swallows `Swift_TransportException` and only logs; `Client::send()` returns nothing. There is no `hasMailConfigured()` in core. | An email button could only ever report "queued", never "delivered". |
| **TimeReport granularity is one-dimensional** — `day`, `week`, `task`, `total`, *or* `user`, never a cross. `bucket()` reads `user_id` only for the `user` case. | A line item cannot be both "task X" and "person Y" from a single `report()` call. |
| **`report()` param 7 accepts `subjectUserIds`**, so per-user breakdowns at any granularity are reachable with N calls — no TimeReport change needed. | Two-level line items are feasible today. |
| **Per-user hours are gated** behind TimeReport's `canReportOnOthers()`. | A non-manager gets a different invoice than a manager for the same range. |
| **Suite convention:** user-scoped config → `user_metadata`; project-scoped → `project_metadata`; global → `configModel`. No DB migrations. | No schema work in either release. |

---

## Decisions

All decisions below are settled. The ID is the question number from the design session.

### Release scope

| ID | Decision | Rationale |
|----|----------|-----------|
| **Q6** | **Two releases.** `1.2.0` = navigation and visibility. `1.3.0` = the money model. | 1.2.0 leaves the **frozen snapshot shape untouched**, so no invoice already stored in `project_has_metadata` changes meaning. 1.3.0 is where the snapshot grows, and it deserves its own care. |

### 1.2.0 — "Every control the user asked for is reachable"

| ID | Decision | Rationale |
|----|----------|-----------|
| **Q1** | **Drop email entirely.** No send-to-client feature. `send()` is renamed **Issue** in the UI. | The mail seam cannot attach a PDF, and a button that can only say "queued" is worse than no button. |
| **Q17** | **Relabel the `sent` status to "Issued"** in `statusLabel()`. The stored value stays `'sent'`. | A data migration over JSON blobs buys tidiness for zero user-visible gain. A button labelled Issue producing a status labelled Sent is the kind of inconsistency that makes people distrust the rest. |
| **Q2** | **Build an invoice show page** (HTML), and add an **inline PDF** view alongside the existing download. | The real gap is not "preview" — it is that an invoice has no detail page. Every action is currently crammed into a table cell. |
| **Q9** | **The list row collapses.** The invoice number becomes a link to the show page; *all* actions move there. | The list's job is to find an invoice and see its status. Destructive actions (Delete, Issue) belong behind a deliberate navigation, not one mis-click in a dense table. |
| **Q15** | **Global list gets a project picker + "New invoice" button.** | `accessibleProjectIds()` already supplies the option list. The top-level Invoices page is the most likely starting point and is currently a dead end. |
| **Q4** | **A Project → Invoice settings page** holding rate, currency, terms days, default notes, **and the client's name/address/email**. Persists to the existing `timeinvoice:defaults` key. | Re-typing the same client on every invoice for the same project is the most obviously wrong thing in the current form. The client belongs to the project; the invoice inherits and may override. |
| **Q16** | **Split the layering:** `terms_days` gets a **per-invoice override** on the form; **currency stays project-level**. The dead `currency` field is deleted from the draft record. | A rush Net-15 invoice is a real scenario; billing one client in two currencies is not. This also makes finding A11 disappear and the stale `freezeSnapshot()` comment deletable. |
| **Q5** | **Live AJAX totals** on the draft form — hours, line count, subtotal, tax, total, recomputed on change. | `freezeSnapshot()` already computes exactly this, and `generateCoverNote()` already proves the AJAX-from-`$form.serialize()` pattern. Biggest UX win, lowest cost. |
| **Q11** | **A read-only under-billing banner** ships in 1.2.0; the real control waits for 1.3.0. | The only option that does not ship a release which silently under-bills, at zero schema cost. It also generates evidence: how often it fires tells us whether Q10's complexity is warranted. |
| — | **Fix settings discoverability**: attach `template:config:sidebar`, render with `layout->config`, throw `AccessForbiddenException`. | Straight house pattern; TimeInvoice is the suite's only divergence (A2, A3). |

**Banner behaviour.** Compare `participants()` for the project and date range against the billed set (self only, in 1.2.0). If others have hours, show a warning. Because `participants()` is gated by `canReportOnOthers()`, a non-manager cannot see names or totals — they get the generic form: *"You may not be seeing all billable hours on this project."* A manager gets the specific form: *"3 other people logged 22.5h in this range that are not on this invoice."*

### 1.3.0 — the money model

| ID | Decision | Rationale |
|----|----------|-----------|
| **Q3** | **Four rate layers:** global default → project rate → **per-user rate** → per-invoice fallback. A user's rate is **global to that user** (not a per-project matrix). Stored in `user_metadata`. | The per-project × per-user matrix is the honest model of consulting but has no natural home in Kanboard's UI; it should be driven by a real need, not symmetry. |
| **Q8** | **Admin-only rate entry**, as a table of all users on the existing TimeInvoice admin settings page. | A billing rate is what the business charges for someone's time, not a personal preference. Letting people set the number that bills the client is a conflict of interest. Also avoids being the first plugin in the suite to attach a `template:user:*` hook. |
| **Q7** | **A "Bill hours for" control** on the form: just me / everyone / specific people, **defaulting to everyone**. Persists as `subject_user_ids` on the draft and the frozen snapshot. | Fixes A10 visibly rather than silently. Because `canReportOnOthers()` gates the data, a silent default would give a manager and a non-manager different invoices with no explanation. This control is also the natural home for per-user rate display. |
| **Q10** | **Two-level line items:** group by user, and within each user use the chosen granularity. Implemented as **N `report()` calls**, one per billed user with `subjectUserIds = [$uid]`, then merged. | The only option where the four rate layers actually reach the PDF. Forcing `granularity=user` produces "Carmelo — 40h — $6,000" and nothing else, which is worse than today for exactly the clients who ask questions. Requires no TimeReport change. |
| **Q12** | The form's rate field becomes a **fallback**, relabelled **"Default rate (for users with no rate set)"**. Users with a rate always use theirs. | Making a *blank* field the power feature is backwards, and an override would mean typing a number into an innocuous box silently rewrites what three people bill at. |
| **Q13** | **Adaptive PDF:** a single biller renders exactly as today; multiple billers render as **per-user sections** (bold name row, that person's lines, a per-user subtotal) before the grand totals. | Solo invoices are the common case and should not grow a column repeating one name down twenty rows. A4 at 95mm of description is already tight. Existing invoices keep rendering byte-identically. |
| **Q14** | **Resolve rates at freeze**, and **display the resolved rates on the show page** so drift is visible before the irreversible step. | Persisting resolved rates on the draft creates the worse failure: you correct a wrong rate in the admin table and drafts keep billing the old one with nothing telling you. Drafts *should* track current rates; they just need to show their work. |
| — | **Remove the 1.2.0 under-billing banner**, superseded by the Q7 control. | — |

---

## Addendum — 2026-09-21: storage was broken on MySQL and Postgres

A user reported `SQLSTATE[22001] ... Data too long for column 'value'`.

**Cause.** Invoices were stored as a single JSON blob in
`project_has_metadata.value`, which is `VARCHAR(255)` on MySQL
(`mysql.sql:340`) and Postgres (`Postgres.php:500`). Measured payloads: a draft
with client details 372 chars, project settings 286, an issued invoice with 8
line items **1,077**. So the plugin worked only on SQLite, which ignores
declared VARCHAR lengths — and which is what the test harness uses, so 99 green
tests never saw it. Outside MySQL strict mode there is no error at all and the
row truncates silently, corrupting a frozen invoice.

Invoice storage was wrong from 1.0.0; the project-settings blob was added in
1.2.0 with the same flaw.

**Fixed in 1.2.2.** Both moved to the plugin's own tables
(`timeinvoice_invoices.record`, `timeinvoice_project_settings.settings`) via a
`Schema/` migration, following the pattern Agents and SchedulerPlugin already
use. This corrects the "no DB migration" line below: that convention holds for
small values, not for records that cannot fit 255 bytes.

Existing rows are **copied, not moved**, so a downgrade to 1.2.1 still finds
its data. Rows already truncated by the bug are skipped rather than failing the
migration, which would otherwise make the plugin impossible to enable.

## Non-goals

- **Email of any kind.** Closed by Q1. `client_email` remains a field on the invoice and is printed on the PDF; it drives nothing.
- **A public/tokenized invoice URL for clients.** Considered and deferred — it is a real auth surface on a plugin that currently has none, and deserves its own design.
- **Per-project × per-user rate matrix.** Closed by Q3 in favour of a user-global rate.
- **Migrating the stored `'sent'` status value.** Closed by Q17.
- **Any DB schema change.** Suite convention; all state stays in `config`, `project_metadata` and `user_metadata`.
- **Changing TimeReport.** Q10 is deliberately implemented with N calls against the existing frozen 8-parameter `report()` contract.

---

## Compatibility

- **Frozen invoices are immutable and must keep rendering.** `sent` and `paid` records store a complete snapshot; neither release rewrites them. 1.3.0's adaptive PDF (Q13) renders a snapshot with no per-user grouping exactly as 1.1.0 did.
- **`InvoicePdf::rateFromSnapshot()`** already back-fills a rate from the first line item when `rate` is absent, and stays as the fallback for old records.
- **Draft records may lose the dead `currency` key** (Q16). This is safe: `freezeSnapshot()` already ignores it and re-derives from layered defaults.
- **TimeReport's 6-positional-argument call shape is pinned by a test on their side** (`TimeReport/Test/TimeReportModelTest.php:769`). 1.3.0 adds a *separate* call site using params 7-8; it does not change the existing one's arity contract.

---

## Verification

Every change is covered by the plugin's PHPUnit suite, run against real Kanboard 1.2.47 core:

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeInvoice
```

Baseline at spec time: **59 tests, 221 assertions, OK** (PHPUnit 9.6.19).

UI-shaped work (templates, AJAX) additionally needs eyes on a running Kanboard —
the dev stack at `testing/docker-compose.dev.yml` (http://localhost:8081, admin/admin).

---

## Plans

- `docs/superpowers/plans/2026-09-20-timeinvoice-1.2.0-navigation.md` — written
- 1.3.0 money model — **deliberately not yet planned.** Q11 ships the under-billing
  banner specifically to generate evidence about how often multi-person billing
  actually occurs. Planning Q10's two-level line items before that evidence exists
  would be guessing at the complexity it warrants. Plan it after 1.2.0 has run on
  live projects.
