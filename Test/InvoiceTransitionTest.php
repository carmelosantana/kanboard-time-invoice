<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
require_once __DIR__ . '/InvoiceSchemaHelper.php';
use Kanboard\Plugin\TimeInvoice\Controller\InvoiceController;
use Kanboard\Plugin\TimeInvoice\Model\InvoiceModel;
use Kanboard\Model\ProjectModel;

/**
 * Status transitions: draft -> sent -> paid, and nothing else.
 */
class InvoiceTransitionTest extends Base
{
    use InvoiceSchemaHelper;

    private function model(): InvoiceModel
    {
        $this->createInvoiceSchema();
        return new InvoiceModel($this->container);
    }

    private function project(): int
    {
        return (new ProjectModel($this->container))->create(['name' => 'Client']);
    }

    public function testCreateDraftRefusesToRewriteAnIssuedInvoice(): void
    {
        $m = $this->model();
        $pid = $this->project();
        $id = $m->createDraft($pid, 1, ['rate' => 100.0]);
        $sent = $m->send($pid, $id, ['issue_date' => '2026-08-23', 'total' => 100.0]);

        try {
            $m->createDraft($pid, 1, ['id' => $id, 'rate' => 1.0]);
            $this->fail('re-saving an issued invoice as a draft must be refused');
        } catch (\DomainException $e) {
        }

        $rec = $m->load($pid, $id);
        $this->assertSame('sent', $rec['status']);
        $this->assertSame($sent['number'], $rec['number']);
        $this->assertSame(100.0, $rec['rate']);
    }

    public function testCreateDraftRefusesToRewriteAPaidInvoice(): void
    {
        $m = $this->model();
        $pid = $this->project();
        $id = $m->createDraft($pid, 1, []);
        $m->send($pid, $id, ['issue_date' => '2026-08-23', 'total' => 0.0]);
        $this->assertTrue($m->markPaid($pid, $id));

        $this->expectException(\DomainException::class);
        $m->createDraft($pid, 1, ['id' => $id]);
    }

    public function testResavingADraftKeepsItsOwner(): void
    {
        $m = $this->model();
        $pid = $this->project();
        $id = $m->createDraft($pid, 7, ['rate' => 100.0]);
        $m->createDraft($pid, 1, ['id' => $id, 'rate' => 120.0]);

        $rec = $m->load($pid, $id);
        $this->assertSame(7, $rec['user_id']);
        $this->assertSame(120.0, $rec['rate']);
    }

    public function testMarkPaidOnlyFromSent(): void
    {
        $m = $this->model();
        $pid = $this->project();
        $id = $m->createDraft($pid, 1, []);

        $this->assertFalse($m->markPaid($pid, $id), 'a draft cannot be paid');
        $this->assertSame('draft', $m->load($pid, $id)['status']);
        $this->assertArrayNotHasKey('paid_at', $m->load($pid, $id));

        $m->send($pid, $id, ['issue_date' => '2026-08-23', 'total' => 0.0]);
        $this->assertTrue($m->markPaid($pid, $id));
        $paidAt = $m->load($pid, $id)['paid_at'];

        $this->assertFalse($m->markPaid($pid, $id), 'already paid');
        $this->assertSame($paidAt, $m->load($pid, $id)['paid_at']);

        $this->assertFalse($m->markPaid($pid, 'missing'));
    }

    public function testSendOnlyFromDraft(): void
    {
        $m = $this->model();
        $pid = $this->project();
        $id = $m->createDraft($pid, 1, []);
        $first = $m->send($pid, $id, ['issue_date' => '2026-08-23', 'total' => 0.0]);

        try {
            $m->send($pid, $id, ['issue_date' => '2026-08-24', 'total' => 0.0]);
            $this->fail('an issued invoice cannot be issued again');
        } catch (\DomainException $e) {
        }

        $this->assertSame($first['number'], $m->load($pid, $id)['number']);
    }

    public function testIsEditableOnlyForDrafts(): void
    {
        $this->assertTrue(InvoiceModel::isEditable(null), 'a new invoice is editable');
        $this->assertTrue(InvoiceModel::isEditable(['status' => 'draft']));
        $this->assertFalse(InvoiceModel::isEditable(['status' => 'sent']));
        $this->assertFalse(InvoiceModel::isEditable(['status' => 'paid']));
        $this->assertFalse(InvoiceModel::isEditable([]));
    }

    public function testSnapshotBillsTheDraftOwnerNotTheActingUser(): void
    {
        $this->createInvoiceSchema();
        $stub = new class {
            public array $users = [];
            public function report($pid, $s, $e, $g, $d, $u) {
                $this->users[] = $u;
                return ['breakdown' => []];
            }
        };
        $this->container['timeReportModel'] = fn ($x) => $stub;
        $this->container['invoiceModel'] = fn ($c) => new InvoiceModel($c);
        $pid = $this->project();
        $id = (new InvoiceModel($this->container))->createDraft($pid, 7, [
            'range' => ['start' => '2026-08-01', 'end' => '2026-08-31'], 'granularity' => 'task', 'rate' => 1.0,
        ]);

        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'snapshotForPdf');
        $m->setAccessible(true);
        $m->invoke($c, $pid, $id, 1);

        $this->assertSame([7], $stub->users);
    }

    public function testControllerGuardsEditAndSaveOnStatus(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Controller/InvoiceController.php');
        foreach (['form', 'saveDraft'] as $action) {
            $this->assertMatchesRegularExpression(
                '/function ' . $action . '\(\): void\s*\{(?:(?!\n    public function).)*isEditable\(/s',
                $src,
                "$action() must refuse non-draft invoices"
            );
        }
    }
}
