# TimeInvoice

Generate polished PDF invoices from your [TimeReport](https://github.com/carmelosantana/kanboard-time-report) hours in Kanboard: pick a project and date range, roll up line items by task, day, or week, set a rate and tax, and track each invoice through draft → sent → paid with stable per-year numbering.

## Purpose

TimeInvoice turns the deduplicated hours TimeReport already aggregates into client-ready invoices. It builds line items from the same completed-work data, applies your rate and tax, and produces a downloadable PDF — no re-entering hours, no separate spreadsheet.

## Requires TimeReport

TimeInvoice depends on the [TimeReport](https://github.com/carmelosantana/kanboard-time-report) plugin (>= 1.1.0), which supplies the hours aggregate that invoice line items are built from. Install and enable TimeReport first.

## Install

Install via [ModMenu](https://github.com/carmelosantana/kanboard-modmenu), or manually:

1. Download the latest release ZIP from GitHub.
2. Extract into your Kanboard `plugins/` directory as `plugins/TimeInvoice/`.
3. Enable the plugin in Kanboard's admin panel under Plugins.

## License

MIT. See LICENSE for details.
