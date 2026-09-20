<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Helper\InvoiceHelper;

class InvoiceHelperTest extends Base
{
    public function testMoneyFormatsWithSymbol(): void
    {
        $h = new InvoiceHelper($this->container);
        $this->assertSame('$1,388.16', $h->money(1388.16, ['code' => 'USD', 'symbol' => '$']));
    }

    public function testStatusLabelAndClass(): void
    {
        $h = new InvoiceHelper($this->container);
        $this->assertSame('Draft', $h->statusLabel('draft'));
        $this->assertSame('Paid', $h->statusLabel('paid'));
        $this->assertNotSame('', $h->statusClass('sent'));
    }

    public function testSentStatusRendersAsIssued(): void
    {
        $h = new \Kanboard\Plugin\TimeInvoice\Helper\InvoiceHelper($this->container);
        $this->assertSame('Issued', $h->statusLabel('sent'), 'no email is sent; the state is "issued"');
        $this->assertSame('timeinvoice-status-sent', $h->statusClass('sent'), 'CSS class keeps the stored value');
        $this->assertSame('Draft', $h->statusLabel('draft'));
        $this->assertSame('Paid', $h->statusLabel('paid'));
    }
}
