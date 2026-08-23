<?php

namespace Kanboard\Plugin\TimeInvoice\Helper;

use Kanboard\Core\Base;

class InvoiceHelper extends Base
{
    public function money(float $amount, array $currency): string
    {
        return ($currency['symbol'] ?? '$') . number_format($amount, 2);
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => t('Draft'),
            'sent'  => t('Sent'),
            'paid'  => t('Paid'),
            default => ucfirst($status),
        };
    }

    public function statusClass(string $status): string
    {
        return match ($status) {
            'draft' => 'timeinvoice-status-draft',
            'sent'  => 'timeinvoice-status-sent',
            'paid'  => 'timeinvoice-status-paid',
            default => 'timeinvoice-status-unknown',
        };
    }
}
