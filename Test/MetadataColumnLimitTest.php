<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
require_once __DIR__ . '/InvoiceSchemaHelper.php';

/**
 * Guards the storage limit the test harness cannot enforce.
 *
 * `project_has_metadata.value` is VARCHAR(255) on BOTH MySQL and Postgres
 * (kanboard-1.2.47/app/Schema/Sql/mysql.sql:340, app/Schema/Postgres.php:500).
 * SQLite ignores declared VARCHAR lengths entirely, and the harness runs on
 * SQLite — so every oversized write passes here and fails in production with
 * `SQLSTATE[22001] ... Data too long for column 'value'` (MySQL 1406), or is
 * silently TRUNCATED when MySQL is not in strict mode, which is worse.
 *
 * These tests assert the invariant directly against the serialized payload, so
 * they are driver-independent.
 */
class MetadataColumnLimitTest extends Base
{
    use InvoiceSchemaHelper;

    /** The hard column width on MySQL and Postgres. */
    private const VALUE_LIMIT = 255;

    /** timeinvoice_invoices.id is VARCHAR(64) on every driver. */
    private const ID_LIMIT = 64;

    private function seedReport(int $lineItems): void
    {
        $this->container['timeReportModel'] = fn ($x) => new class ($lineItems) {
            public function __construct(private int $n) {}
            public function report($pid, $s, $e, $g, $d, $u) {
                $b = [];
                for ($i = 1; $i <= $this->n; $i++) {
                    $b[] = ['key' => (string) $i, 'label' => "#$i Some task title here", 'hours' => 3.5, 'task_count' => 1];
                }
                return ['breakdown' => $b];
            }
        };
    }

    /** The id is the PRIMARY KEY, declared VARCHAR(64) on every driver. */
    public function testInvoiceIdFitsThePrimaryKeyColumn(): void
    {
        $this->createInvoiceSchema();
        $this->container['invoiceModel'] = fn ($c) => new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($c);
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);
        $model = new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($this->container);
        $id = $model->createDraft($pid, 1, ['range' => ['start' => '2026-08-01', 'end' => '2026-08-31'], 'granularity' => 'task', 'rate' => 1.0]);

        $this->assertLessThanOrEqual(self::ID_LIMIT, strlen($id), "invoice id '$id' exceeds the VARCHAR(64) primary key");
    }

    /**
     * The regression this guards: an issued invoice must not be written to a
     * VARCHAR(255) metadata column. It now lives in timeinvoice_invoices,
     * whose `record` column is MEDIUMTEXT/TEXT.
     */
    public function testIssuedInvoiceIsNotStoredInMetadata(): void
    {
        $this->createInvoiceSchema();
        $this->seedReport(8);
        $this->container['invoiceModel'] = fn ($c) => new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($c);
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'Acme']);
        $model = new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($this->container);

        $id = $model->createDraft($pid, 1, [
            'range' => ['start' => '2026-08-01', 'end' => '2026-08-31'],
            'granularity' => 'task', 'rate' => 150.0,
            'client' => ['name' => 'Acme Corporation Ltd', 'address' => "100 Industrial Parkway\nSuite 400", 'email' => 'ap@acme.example'],
        ]);

        $c = new \Kanboard\Plugin\TimeInvoice\Controller\InvoiceController($this->container);
        $f = new ReflectionMethod($c, 'snapshotForPdf');
        $f->setAccessible(true);
        $model->send($pid, $id, $f->invoke($c, $pid, $id, 1));

        $this->assertSame('', $this->container['projectMetadataModel']->get($pid, 'timeinvoice:inv:' . $id, ''),
            'invoices must no longer touch the VARCHAR(255) metadata column');

        $stored = $this->container['db']->table('timeinvoice_invoices')->eq('id', $id)->findOneColumn('record');
        $this->assertGreaterThan(self::VALUE_LIMIT, strlen($stored),
            'sanity: this record really is bigger than the old column could hold');
        $this->assertSame('sent', json_decode($stored, true)['status'], 'and it round-trips intact');
    }

    /** A draft with client details also exceeded the old column. */
    public function testDraftRoundTripsIntactAtAnySize(): void
    {
        $this->createInvoiceSchema();
        $this->container['invoiceModel'] = fn ($c) => new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($c);
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);
        $model = new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($this->container);
        $notes = str_repeat('Payment due within 15 days. ', 40); // ~1.1KB on its own
        $id = $model->createDraft($pid, 1, [
            'range' => ['start' => '2026-08-01', 'end' => '2026-08-31'],
            'granularity' => 'task', 'rate' => 150.0,
            'client' => ['name' => 'Acme Corporation Ltd', 'address' => "100 Industrial Parkway\nSuite 400", 'email' => 'ap@acme.example'],
            'notes' => $notes,
        ]);

        $loaded = $model->load($pid, $id);
        $this->assertSame($notes, $loaded['notes'], 'a long note must survive the round trip untruncated');
        $this->assertSame('Acme Corporation Ltd', $loaded['client']['name']);
    }

    /** The per-project settings blob (1.2.0) had the same overflow. */
    public function testProjectDefaultsRoundTripIntactAndAvoidMetadata(): void
    {
        $this->createInvoiceSchema();
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);

        $ps = new \Kanboard\Plugin\TimeInvoice\Controller\ProjectSettingsController($this->container);
        $bd = new ReflectionMethod($ps, 'buildDefaults');
        $bd->setAccessible(true);
        $defaults = $bd->invoke($ps, [
            'rate' => '165.50', 'currency_code' => 'USD', 'currency_symbol' => '$', 'terms_days' => '15',
            'terms' => 'Net 15. Late payments accrue 1.5% monthly interest.',
            'client_name' => 'Acme Corporation Ltd',
            'client_address' => "100 Industrial Parkway\nSuite 400\nNewburgh, NY 12550",
            'client_email' => 'accounts.payable@acmecorp.example.com',
        ]);
        $this->assertGreaterThan(self::VALUE_LIMIT, strlen(json_encode($defaults)), 'sanity: bigger than the old column');

        (new \Kanboard\Plugin\TimeInvoice\Model\ProjectSettingsModel($this->container))->save($pid, $defaults);

        $this->assertSame('', $this->container['projectMetadataModel']->get($pid, 'timeinvoice:defaults', ''),
            'project settings must no longer touch the VARCHAR(255) metadata column');

        $ic = new \Kanboard\Plugin\TimeInvoice\Controller\InvoiceController($this->container);
        $pd = new ReflectionMethod($ic, 'projectDefaults');
        $pd->setAccessible(true);
        $read = $pd->invoke($ic, $pid);
        $this->assertSame('accounts.payable@acmecorp.example.com', $read['client']['email'], 'full address and email survive');
        $this->assertSame(15, $read['terms_days']);
    }

}
