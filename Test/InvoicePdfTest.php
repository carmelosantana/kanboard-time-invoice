<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Model\InvoicePdf;

class InvoicePdfTest extends Base
{
    private function snapshot(string $status = 'sent'): array
    {
        return [
            'status'     => $status,
            'number'     => $status === 'draft' ? null : 'INV-2026-001',
            'issue_date' => '2026-08-23',
            'due_date'   => '2026-09-22',
            'range'      => ['start' => '2026-08-01', 'end' => '2026-08-31'],
            'currency'   => ['code' => 'USD', 'symbol' => '$'],
            'business'   => ['name' => 'Carmelo Santana', 'address' => "Newburgh, NY", 'email' => 'me@carmelosantana.com'],
            'client'     => ['name' => 'Acme Inc', 'address' => "123 Main St", 'email' => 'ap@acme.test'],
            'line_items' => [['label' => '#1 Homepage build', 'hours' => 8.5, 'amount' => 1275.0]],
            'subtotal'   => 1275.0,
            'tax'        => ['enabled' => true, 'rate' => 8.875, 'amount' => 113.16],
            'total'      => 1388.16,
            'notes'      => 'Thank you for your business.',
        ];
    }

    public function testRendersValidPdf(): void
    {
        $bytes = (new InvoicePdf($this->container))->render($this->snapshot());
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(800, strlen($bytes));
    }

    public function testDraftAlsoRenders(): void
    {
        $bytes = (new InvoicePdf($this->container))->render($this->snapshot('draft'));
        $this->assertStringStartsWith('%PDF-', $bytes);
    }

    public function testNonAsciiCurrencySymbolRenders(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['currency'] = ['code' => 'GBP', 'symbol' => '£'];
        $bytes = (new InvoicePdf($this->container))->render($snapshot);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(800, strlen($bytes));
    }

    public function testToCp1252ProducesCorrectBytes(): void
    {
        $this->assertSame('Hello', \Kanboard\Plugin\TimeInvoice\Model\InvoicePdf::toCp1252('Hello'));
        $this->assertSame("\xA3", \Kanboard\Plugin\TimeInvoice\Model\InvoicePdf::toCp1252('£')); // GBP U+00A3 -> 0xA3
        $this->assertSame("\x80", \Kanboard\Plugin\TimeInvoice\Model\InvoicePdf::toCp1252('€')); // EUR U+20AC -> 0x80
    }
}
