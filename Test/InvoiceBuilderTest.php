<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Model\InvoiceBuilder;

class InvoiceBuilderTest extends Base
{
    public function testLineItemsMapBreakdownRowsAndMultiply(): void
    {
        $breakdown = [
            ['key' => '1', 'label' => '#1 Homepage', 'hours' => 8.5, 'task_count' => 1],
            ['key' => '2', 'label' => '#2 API',      'hours' => 3.0, 'task_count' => 1],
        ];
        $items = InvoiceBuilder::lineItems($breakdown, 150.0);
        $this->assertSame('#1 Homepage', $items[0]['label']);
        $this->assertSame(8.5, $items[0]['hours']);
        $this->assertSame(1275.0, $items[0]['amount']);
        $this->assertSame(450.0, $items[1]['amount']);
    }

    public function testTotalsWithTaxRoundToCents(): void
    {
        $items = [['label' => 'x', 'hours' => 8.5, 'amount' => 1275.0]];
        $t = InvoiceBuilder::totals($items, true, 8.875);
        $this->assertSame(1275.0, $t['subtotal']);
        $this->assertSame(113.16, $t['tax']);   // round(1275 * 0.08875, 2)
        $this->assertSame(1388.16, $t['total']);
    }

    public function testTotalsTaxDisabled(): void
    {
        $items = [['label' => 'x', 'hours' => 1.0, 'amount' => 100.0]];
        $t = InvoiceBuilder::totals($items, false, 8.875);
        $this->assertSame(0.0, $t['tax']);
        $this->assertSame(100.0, $t['total']);
    }

    public function testEmptyBreakdownYieldsZeroTotals(): void
    {
        $t = InvoiceBuilder::totals(InvoiceBuilder::lineItems([], 150.0), true, 10.0);
        $this->assertSame(0.0, $t['subtotal']);
        $this->assertSame(0.0, $t['total']);
    }
}
