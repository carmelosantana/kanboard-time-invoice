<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Controller\InvoiceController;

class InvoiceControllerTest extends Base
{
    public function testDependencyGateFalseWithoutTimeReport(): void
    {
        $c = new InvoiceController($this->container);
        $ref = new ReflectionMethod($c, 'hasTimeReport');
        $ref->setAccessible(true);
        $this->assertFalse($ref->invoke($c));
        $this->container['timeReportModel'] = fn ($x) => new stdClass();
        $this->assertTrue($ref->invoke($c));
    }

    public function testGlobalDefaultsDecodeFromConfig(): void
    {
        $this->container['configModel']->save(['timeinvoice_business' => json_encode(['name' => 'Me'])]);
        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'globalDefaults');
        $m->setAccessible(true);
        $defaults = $m->invoke($c);
        $this->assertSame('Me', $defaults['business']['name']);
    }

    public function testFreezeSnapshotBuildsLineItemsAndTotals(): void
    {
        $this->container['timeReportModel'] = fn ($x) => new class {
            public function report($pid, $s, $e, $g, $d, $u) {
                return ['breakdown' => [['key' => '1', 'label' => '#1 Task', 'hours' => 10.0, 'task_count' => 1]]];
            }
        };
        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'freezeSnapshot');
        $m->setAccessible(true);
        $draft = [
            'project_id' => 5, 'range' => ['start' => '2026-08-01', 'end' => '2026-08-31'],
            'granularity' => 'task', 'rate' => 150.0, 'tax_enabled' => true, 'tax_rate' => 10.0,
            'currency' => ['code' => 'USD', 'symbol' => '$'], 'client' => ['name' => 'Acme'],
            'terms_days' => 30, 'issue_date' => '2026-08-23',
        ];
        $snap = $m->invoke($c, $draft, 1);
        $this->assertSame(1500.0, $snap['subtotal']);
        $this->assertSame(150.0, $snap['tax']['amount']);
        $this->assertSame(1650.0, $snap['total']);
        $this->assertSame('2026-09-22', $snap['due_date']); // issue + 30 days
        $this->assertSame('#1 Task', $snap['line_items'][0]['label']);
    }
}
