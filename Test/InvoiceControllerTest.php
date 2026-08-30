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

    public function testAiReadyFalseWithoutAiConnector(): void
    {
        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'aiReady');
        $m->setAccessible(true);
        $this->assertFalse($m->invoke($c), 'no AiConnector classes loaded → not ready');
    }

    public function testAiProfilesEmptyWithoutAiConnector(): void
    {
        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'aiProfiles');
        $m->setAccessible(true);
        $this->assertSame([], $m->invoke($c));
        $d = new ReflectionMethod($c, 'aiDefaultProfile');
        $d->setAccessible(true);
        $this->assertSame('', $d->invoke($c));
    }

    public function testPdfPreviewForDraftRendersBytes(): void
    {
        $this->container['timeReportModel'] = fn ($x) => new class {
            public function report($pid, $s, $e, $g, $d, $u) {
                return ['breakdown' => [['key' => '1', 'label' => '#1', 'hours' => 2.0, 'task_count' => 1]]];
            }
        };
        $this->container['invoiceModel'] = fn ($c) => new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($c);
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);
        $model = new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($this->container);
        $id = $model->createDraft($pid, 1, ['range' => ['start' => '2026-08-01', 'end' => '2026-08-31'], 'granularity' => 'task', 'rate' => 100.0, 'currency' => ['code' => 'USD', 'symbol' => '$']]);

        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'snapshotForPdf');
        $m->setAccessible(true);
        $snap = $m->invoke($c, $pid, $id, 1);
        $bytes = (new \Kanboard\Plugin\TimeInvoice\Model\InvoicePdf($this->container))->render($snap);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame('draft', $snap['status']);
    }

    public function testCoverNoteContextMapsRequestValues(): void
    {
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'Acme']);
        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'coverNoteContext');
        $m->setAccessible(true);
        $v = [
            'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'granularity' => 'week',
            'rate' => '150', 'tax_enabled' => '1', 'tax_rate' => '10',
            'client_name' => 'Acme Inc', 'profile_id' => '',
        ];
        $ctx = $m->invoke($c, $v, $pid, 1);
        $this->assertSame($pid, $ctx['project_id']);
        $this->assertSame(1, $ctx['user_id']);
        $this->assertSame('week', $ctx['granularity']);
        $this->assertSame(150.0, $ctx['rate']);
        $this->assertTrue($ctx['tax_enabled']);
        $this->assertSame('Acme Inc', $ctx['client']['name']);
        $this->assertSame('Acme', $ctx['project_name']);
        $this->assertNull($ctx['profile_id'], 'empty profile_id maps to null (use default)');
    }

    public function testCoverNoteContextClampsBadGranularityAndDates(): void
    {
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);
        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'coverNoteContext');
        $m->setAccessible(true);
        $ctx = $m->invoke($c, ['granularity' => 'bogus', 'start_date' => 'nope', 'profile_id' => 'p2'], $pid, 1);
        $this->assertSame('task', $ctx['granularity'], 'invalid granularity falls back to task');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $ctx['start']);
        $this->assertSame('p2', $ctx['profile_id']);
    }

    /**
     * Regression (live E2E): the invoice form AJAXes project_id in the POST body
     * (via $form.serialize(), no query string). generateCoverNote() must resolve
     * it from getValues() ($_POST), NOT getIntegerParam() ($_GET) — otherwise the
     * access guard sees 0 and every generate returns 400. This pins the seam that
     * reads project_id from the body with the query string empty.
     */
    public function testRequestProjectIdReadsFromPostBody(): void
    {
        $csrf = $this->container['token']->getCSRFToken();
        // GET (3rd arg) is empty; project_id lives ONLY in the POST body (4th arg).
        $this->container['request'] = new \Kanboard\Core\Http\Request($this->container, [], [], [
            'csrf_token' => $csrf,
            'project_id' => '42',
        ]);

        // getValues() is single-use/stateful: read it ONCE, feed the seam.
        $values = $this->container['request']->getValues();

        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'requestProjectId');
        $m->setAccessible(true);
        $this->assertSame(42, $m->invoke($c, $values), 'project_id must be read from the POST body even when the query string is empty');
        $this->assertSame(0, $m->invoke($c, []), 'missing project_id → 0');
        $this->assertSame(0, $m->invoke($c, ['project_id' => 'abc']), 'non-numeric project_id → 0');
    }
}
