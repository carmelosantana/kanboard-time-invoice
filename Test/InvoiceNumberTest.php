<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Model\InvoiceNumber;

class InvoiceNumberTest extends Base
{
    public function testFirstNumberOfYear(): void
    {
        $r = InvoiceNumber::next([], '2026-08-23', 'INV-{YYYY}-{seq}');
        $this->assertSame('INV-2026-001', $r['number']);
        $this->assertSame(['2026' => 1], $r['counter']);
    }

    public function testIncrementsWithinYear(): void
    {
        $r = InvoiceNumber::next(['2026' => 4], '2026-09-01', 'INV-{YYYY}-{seq}');
        $this->assertSame('INV-2026-005', $r['number']);
        $this->assertSame(['2026' => 5], $r['counter']);
    }

    public function testResetsPerYear(): void
    {
        $r = InvoiceNumber::next(['2026' => 12], '2027-01-02', 'INV-{YYYY}-{seq}');
        $this->assertSame('INV-2027-001', $r['number']);
        $this->assertSame(['2026' => 12, '2027' => 1], $r['counter']);
    }
}
