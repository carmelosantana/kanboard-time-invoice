# TimeInvoice

Generate polished PDF invoices from your [TimeReport](https://github.com/carmelosantana/kanboard-time-report) hours in Kanboard: pick a project and date range, roll up line items by task, day, or week, set a rate and tax, and track each invoice through draft → sent → paid with stable per-year numbering.

## Purpose

TimeInvoice turns the deduplicated hours TimeReport already aggregates into client-ready invoices. It builds line items from the same completed-work data, applies your rate and tax, and produces a downloadable PDF — no re-entering hours, no separate spreadsheet.

## Using it

**Set your business details, default rate, currency, payment terms and invoice
number format** in *Settings → Invoices* (admin only).

**Set a project's rate, currency, payment terms and client** in the project
sidebar under *Invoice settings*. Invoices for that project inherit these; you
can override the rate, terms and client on an individual invoice.

**Create an invoice** from the project sidebar (*Invoices → New invoice*) or from
the top-level *Invoices* page, which has a project picker. As you set the date
range, grouping, rate and tax, the form shows live hours and totals.

**Look at an invoice** by clicking its number in the list. The invoice page shows
the line items and totals, and carries every action: download the PDF, view it
inline, edit, issue, delete, and mark paid.

**Issue an invoice** to freeze its snapshot and assign its number. TimeInvoice
does not email invoices — Kanboard's mail transport cannot carry a PDF
attachment. Download or view the PDF and send it however you normally do.

> **Multi-person projects:** this version bills only *your own* hours. Where
> other people have logged time in the range, the invoice form warns you. Billing
> several people, each at their own rate, arrives in 1.3.0.

## Storage

Invoices live in the plugin's own `timeinvoice_invoices` and
`timeinvoice_project_settings` tables, created automatically on enable or
upgrade. Before 1.3.0 they were stored in Kanboard's `project_has_metadata`,
whose `value` column is `VARCHAR(255)` on MySQL and Postgres — far too small
for an invoice, which failed with `SQLSTATE[22001] Data too long` or truncated
silently. Existing records are copied over on upgrade, and the old rows are
left in place so a downgrade still finds them.

## Requires TimeReport

TimeInvoice depends on the [TimeReport](https://github.com/carmelosantana/kanboard-time-report) plugin (>= 1.1.0), which supplies the hours aggregate that invoice line items are built from. Install and enable TimeReport first.

## Install

Install via [ModMenu](https://github.com/carmelosantana/kanboard-modmenu), or manually:

1. Download the latest release ZIP from GitHub.
2. Extract into your Kanboard `plugins/` directory as `plugins/TimeInvoice/`.
3. Enable the plugin in Kanboard's admin panel under Plugins.

## License

MIT. See LICENSE for details.
