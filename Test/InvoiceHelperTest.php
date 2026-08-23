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
}
