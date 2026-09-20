<?php

namespace Kanboard\Plugin\TimeInvoice;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize(): void
    {
        $this->container['invoiceModel'] = fn ($c) => new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($c);
        $this->container['invoicePdf']   = fn ($c) => new \Kanboard\Plugin\TimeInvoice\Model\InvoicePdf($c);
        $this->container['coverNoteGenerator'] = fn ($c) => new \Kanboard\Plugin\TimeInvoice\Model\CoverNoteGenerator($c);

        $this->helper->register('invoice', \Kanboard\Plugin\TimeInvoice\Helper\InvoiceHelper::class);

        // Routes (clean-URL ids).
        $this->route->addRoute('timeinvoice', 'InvoiceController', 'list', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/project', 'InvoiceController', 'project', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/form', 'InvoiceController', 'form', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/show', 'InvoiceController', 'show', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/save', 'InvoiceController', 'saveDraft', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/send', 'InvoiceController', 'send', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/paid', 'InvoiceController', 'markPaid', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/delete', 'InvoiceController', 'delete', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/pdf', 'InvoiceController', 'pdf', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/preview-totals', 'InvoiceController', 'previewTotals', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/generate-note', 'InvoiceController', 'generateCoverNote', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/project/settings', 'ProjectSettingsController', 'show', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/project/settings/save', 'ProjectSettingsController', 'save', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/settings', 'SettingsController', 'show', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/settings/save', 'SettingsController', 'save', 'TimeInvoice');

        // Entry points.
        $this->template->hook->attach('template:project:sidebar', 'TimeInvoice:invoice/sidebar');
        $this->template->hook->attach('template:header:dropdown', 'TimeInvoice:invoice/header_dropdown');
        $this->hook->on('template:config:sidebar', ['template' => 'TimeInvoice:config/sidebar']);

        // Assets (CSP-safe external files).
        $this->hook->on('template:layout:css', ['template' => 'plugins/TimeInvoice/Assets/css/timeinvoice.css']);
        $this->hook->on('template:layout:js', ['template' => 'plugins/TimeInvoice/Assets/js/timeinvoice.js']);
    }

    /** Defensive dependency gate: TimeReport registers timeReportModel on the container. */
    public function requiresTimeReport(): bool
    {
        return isset($this->container['timeReportModel']);
    }

    public function isPhpCompatible(?int $versionId = null): bool
    {
        return ($versionId ?? PHP_VERSION_ID) >= 80400;
    }

    public function getPluginName(): string        { return 'TimeInvoice'; }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '1.1.0'; }
    public function getPluginLicense(): string     { return 'MIT'; }
    public function getPluginHomepage(): string    { return 'https://github.com/carmelosantana/kanboard-time-invoice'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }

    public function getPluginDescription(): string
    {
        return t('Generate polished PDF invoices from your TimeReport hours, tracked through draft, sent and paid with per-year numbering.');
    }
}
