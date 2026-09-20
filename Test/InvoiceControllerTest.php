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

    public function testAccessibleProjectsReturnsIdNameMapForGuardedIdsOnly(): void
    {
        $this->loginAsAdmin();
        $pm = new \Kanboard\Model\ProjectModel($this->container);
        $a = $pm->create(['name' => 'Zulu Project']);
        $b = $pm->create(['name' => 'Alpha Project']);
        // accessibleProjectIds() is membership-based (getActiveProjectsByUser),
        // so being an app admin is NOT enough — seat the user on both projects.
        $this->container['projectUserRoleModel']->addUser($a, 1, \Kanboard\Core\Security\Role::PROJECT_MANAGER);
        $this->container['projectUserRoleModel']->addUser($b, 1, \Kanboard\Core\Security\Role::PROJECT_MANAGER);

        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'accessibleProjects');
        $m->setAccessible(true);
        $projects = $m->invoke($c, 1);

        $ids = new ReflectionMethod($c, 'accessibleProjectIds');
        $ids->setAccessible(true);
        $guarded = $ids->invoke($c, 1);

        sort($guarded);
        $offered = array_keys($projects);
        sort($offered);
        $this->assertNotSame([], $offered, 'sanity: the fixture must grant access to something');
        $this->assertSame($guarded, $offered, 'picker options must be exactly the ids the access guard allows');
        $this->assertSame('Alpha Project', reset($projects), 'sorted by name, not id');
        $this->assertArrayHasKey($a, $projects);
        $this->assertArrayHasKey($b, $projects);
    }

    public function testAccessibleProjectsEmptyWhenNoProjects(): void
    {
        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'accessibleProjects');
        $m->setAccessible(true);
        $this->assertSame([], $m->invoke($c, 99));
    }

    /** Seat an app-admin user in the session (harness starts with an empty session). */
    private function loginAsAdmin(): void
    {
        $_SESSION['user'] = ['id' => 1, 'role' => \Kanboard\Core\Security\Role::APP_ADMIN];
    }

    public function testFormValuesInheritProjectClientAndAreOverriddenByDraft(): void
    {
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);
        $this->container['projectMetadataModel']->save($pid, [
            'timeinvoice:defaults' => json_encode([
                'rate'   => 165.0,
                'client' => ['name' => 'Acme Inc', 'email' => 'ap@acme.example'],
            ]),
        ]);

        $c = new InvoiceController($this->container);
        $g = new ReflectionMethod($c, 'globalDefaults');
        $g->setAccessible(true);
        $pd = new ReflectionMethod($c, 'projectDefaults');
        $pd->setAccessible(true);

        $merged = \Kanboard\Plugin\TimeInvoice\Model\DefaultsResolver::resolve(
            $g->invoke($c), $pd->invoke($c, $pid), []
        );
        $this->assertSame('Acme Inc', $merged['client']['name']);
        // Written here with plain json_encode(), so 165.0 round-trips as int 165.
        // The controller's own writes use JSON_PRESERVE_ZERO_FRACTION; either way
        // every consumer casts, so assert the value, not the PHP type.
        $this->assertEquals(165.0, $merged['rate']);

        $merged2 = \Kanboard\Plugin\TimeInvoice\Model\DefaultsResolver::resolve(
            $g->invoke($c), $pd->invoke($c, $pid), ['client' => ['name' => 'Beta LLC']]
        );
        $this->assertSame('Beta LLC', $merged2['client']['name'], 'draft client must override the project client');
    }

    public function testShowRouteRegistered(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Plugin.php');
        $this->assertStringContainsString("'timeinvoice/show'", $src);
    }

    public function testSnapshotForPdfDrivesShowForDraftAndFrozenForSent(): void
    {
        $this->container['timeReportModel'] = fn ($x) => new class {
            public function report($pid, $s, $e, $g, $d, $u) {
                return ['breakdown' => [['key' => '1', 'label' => '#1 Task', 'hours' => 4.0, 'task_count' => 1]]];
            }
        };
        // Plugin::initialize() does not run under the harness, so the container
        // has no invoiceModel — snapshotForPdf() needs it. Register it explicitly.
        $this->container['invoiceModel'] = fn ($c) => new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($c);
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);
        $model = new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($this->container);
        $id = $model->createDraft($pid, 1, [
            'range' => ['start' => '2026-08-01', 'end' => '2026-08-31'],
            'granularity' => 'task', 'rate' => 100.0,
        ]);

        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'snapshotForPdf');
        $m->setAccessible(true);

        $draftSnap = $m->invoke($c, $pid, $id, 1);
        $this->assertSame('draft', $draftSnap['status']);
        $this->assertNull($draftSnap['number'], 'a draft has no number until issued');
        $this->assertSame(400.0, $draftSnap['total']);

        $model->send($pid, $id, $m->invoke($c, $pid, $id, 1));
        $sentSnap = $m->invoke($c, $pid, $id, 1);
        $this->assertSame('sent', $sentSnap['status']);
        $this->assertNotNull($sentSnap['number'], 'an issued invoice carries its frozen number');
    }

    public function testContentDispositionSwitchesOnInlineFlag(): void
    {
        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'contentDisposition');
        $m->setAccessible(true);
        $this->assertSame('attachment; filename="INV-2026-001.pdf"', $m->invoke($c, false, 'INV-2026-001.pdf'));
        $this->assertSame('inline; filename="INV-2026-001.pdf"', $m->invoke($c, true, 'INV-2026-001.pdf'));
        $this->assertSame('inline; filename="draft.pdf"', $m->invoke($c, true, 'draft.pdf'));
    }
}
