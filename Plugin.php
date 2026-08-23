<?php

namespace Kanboard\Plugin\TimeInvoice;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize(): void
    {
        // Model services and route/hook wiring are added by later tasks.
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
    public function getPluginVersion(): string     { return '0.1.0'; }
    public function getPluginLicense(): string     { return 'MIT'; }
    public function getPluginHomepage(): string    { return 'https://github.com/carmelosantana/kanboard-time-invoice'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }

    public function getPluginDescription(): string
    {
        return t('Generate polished PDF invoices from your TimeReport hours, tracked through draft, sent and paid with per-year numbering.');
    }
}
