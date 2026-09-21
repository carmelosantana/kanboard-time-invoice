<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
require_once __DIR__ . '/InvoiceSchemaHelper.php';
use Kanboard\Plugin\TimeInvoice\Model\InvoiceModel;
use Kanboard\Model\ProjectModel;

class InvoiceModelTest extends Base
{
    use InvoiceSchemaHelper;

    private function seedProject(): int
    {
        $p = new ProjectModel($this->container);
        return $p->create(['name' => 'Client A']);
    }

    public function testCreateLoadListDraft(): void
    {
        $this->createInvoiceSchema();
        $pid = $this->seedProject();
        $m = new InvoiceModel($this->container);
        $id = $m->createDraft($pid, 1, ['range' => ['start' => '2026-08-01', 'end' => '2026-08-31'], 'rate' => 150.0]);
        $this->assertNotEmpty($id);

        $rec = $m->load($pid, $id);
        $this->assertSame('draft', $rec['status']);
        $this->assertSame($id, $rec['id']);
        $this->assertSame(150.0, $rec['rate']);
        $this->assertArrayNotHasKey('number', $rec);

        $list = $m->listByProject($pid);
        $this->assertCount(1, $list);
    }

    public function testSendAssignsNumberFreezesAndCountsOutstanding(): void
    {
        $this->createInvoiceSchema();
        $pid = $this->seedProject();
        $m = new InvoiceModel($this->container);
        $id = $m->createDraft($pid, 1, ['range' => ['start' => '2026-08-01', 'end' => '2026-08-31']]);

        $snap = ['issue_date' => '2026-08-23', 'line_items' => [['label' => 'x', 'hours' => 1, 'amount' => 100.0]], 'total' => 100.0];
        $sent = $m->send($pid, $id, $snap);
        $this->assertSame('sent', $sent['status']);
        $this->assertSame('INV-2026-001', $sent['number']);
        $this->assertSame(100.0, $sent['total']);

        $this->assertSame(100.0, $m->outstandingTotal([$pid]));

        $m->markPaid($pid, $id);
        $this->assertSame('paid', $m->load($pid, $id)['status']);
        $this->assertSame(0.0, $m->outstandingTotal([$pid]));
    }

    public function testNumbersAreGapFreeAcrossTwoSends(): void
    {
        $this->createInvoiceSchema();
        $pid = $this->seedProject();
        $m = new InvoiceModel($this->container);
        $a = $m->createDraft($pid, 1, []);
        $b = $m->createDraft($pid, 1, []);
        $m->delete($pid, $a); // deleting a draft consumes no number
        $sentB = $m->send($pid, $b, ['issue_date' => '2026-08-23', 'total' => 0.0]);
        $this->assertSame('INV-2026-001', $sentB['number']);
    }

    public function testCreateDraftReusesProvidedId(): void
    {
        $this->createInvoiceSchema();
        $pid = $this->seedProject();
        $m = new InvoiceModel($this->container);
        $id = $m->createDraft($pid, 1, ['rate' => 100.0]);

        // Editing in place: pass the existing id back — same id round-trips, no new row.
        $again = $m->createDraft($pid, 1, ['id' => $id, 'rate' => 175.0]);
        $this->assertSame($id, $again);

        $rec = $m->load($pid, $id);
        $this->assertSame($id, $rec['id']);
        $this->assertSame(175.0, $rec['rate']);
        $this->assertCount(1, $m->listByProject($pid));
    }

    public function testListAllSpansProjects(): void
    {
        $this->createInvoiceSchema();
        $p1 = $this->seedProject();
        $p2 = $this->seedProject();
        $m = new InvoiceModel($this->container);
        $m->createDraft($p1, 1, []);
        $m->createDraft($p2, 1, []);
        $this->assertCount(2, $m->listAll([$p1, $p2]));
        $this->assertCount(1, $m->listAll([$p1]));
    }
}
