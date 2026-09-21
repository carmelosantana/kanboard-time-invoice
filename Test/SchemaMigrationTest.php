<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;

/**
 * The upgrade path for installs that already hold invoices.
 *
 * version_1 creates the plugin's tables and COPIES existing
 * `timeinvoice:*` rows out of project_has_metadata. It copies rather than
 * moves, so downgrading to 1.2.1 still finds its data.
 *
 * This runs the real migration function — the same one Kanboard's SchemaHandler
 * calls when the plugin is enabled or updated.
 */
class SchemaMigrationTest extends Base
{
    private function migrate(): void
    {
        require_once dirname(__DIR__) . '/Schema/Sqlite.php';
        \Kanboard\Plugin\TimeInvoice\Schema\version_1($this->container['db']->getConnection());
    }

    private function seedLegacyInvoice(int $projectId, string $id, array $overrides = []): array
    {
        $record = array_merge([
            'id'         => $id,
            'project_id' => $projectId,
            'user_id'    => 1,
            'status'     => 'sent',
            'created_at' => '2026-08-01 10:00:00',
            'number'     => 'INV-2026-001',
            'total'      => 1650.0,
            'client'     => ['name' => 'Acme Inc', 'address' => '100 Industrial Parkway', 'email' => 'ap@acme.example'],
            'line_items' => [['label' => '#1 Migration work', 'hours' => 10.0, 'amount' => 1500.0]],
            'notes'      => 'Thanks for your business.',
        ], $overrides);

        // JSON_PRESERVE_ZERO_FRACTION mirrors exactly what 1.2.1's InvoiceModel
        // wrote, so the fixture is faithful legacy data.
        $this->container['projectMetadataModel']->save($projectId, [
            'timeinvoice:inv:' . $id => json_encode($record, JSON_PRESERVE_ZERO_FRACTION),
        ]);
        return $record;
    }

    public function testMigrationCopiesLegacyInvoicesIntoTheNewTable(): void
    {
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'Acme']);
        $this->seedLegacyInvoice($pid, '20260801100000-aaaa1111');
        $this->seedLegacyInvoice($pid, '20260901100000-bbbb2222', [
            'status' => 'draft', 'number' => null, 'created_at' => '2026-09-01 10:00:00',
        ]);

        $this->migrate();

        $model = new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($this->container);
        $all = $model->listByProject($pid);

        $this->assertCount(2, $all, 'both legacy invoices migrated');
        $this->assertSame('20260901100000-bbbb2222', $all[0]['id'], 'newest first is preserved');

        $sent = $model->load($pid, '20260801100000-aaaa1111');
        $this->assertSame('sent', $sent['status']);
        $this->assertSame('INV-2026-001', $sent['number'], 'the frozen number survives');
        $this->assertSame('ap@acme.example', $sent['client']['email'], 'nested client data survives');
        $this->assertSame(1650.0, $sent['total']);
    }

    public function testMigrationLeavesLegacyRowsInPlaceForRollback(): void
    {
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);
        $this->seedLegacyInvoice($pid, '20260801100000-cccc3333');

        $this->migrate();

        $legacy = $this->container['projectMetadataModel']->get($pid, 'timeinvoice:inv:20260801100000-cccc3333', '');
        $this->assertNotSame('', $legacy, 'the original metadata row must survive so a downgrade to 1.2.1 still works');
        $this->assertSame('sent', json_decode($legacy, true)['status']);
    }

    /**
     * On MySQL the old rows may have been truncated mid-JSON by the very bug
     * this migration fixes. Those are unrecoverable — but they must not stop
     * the migration, or the plugin cannot be enabled at all.
     */
    public function testMigrationSkipsCorruptRowsWithoutFailing(): void
    {
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);
        $good = $this->seedLegacyInvoice($pid, '20260801100000-dddd4444');

        // A row truncated at 255 chars, exactly as MySQL would have left it.
        $this->container['projectMetadataModel']->save($pid, [
            'timeinvoice:inv:20260801100000-eeee5555' => substr(json_encode($good), 0, 255),
        ]);

        $this->migrate();

        $model = new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($this->container);
        $all = $model->listByProject($pid);
        $this->assertCount(1, $all, 'the intact invoice migrated; the truncated one was skipped');
        $this->assertSame('20260801100000-dddd4444', $all[0]['id']);
    }

    public function testMigrationCopiesLegacyProjectSettings(): void
    {
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);
        $this->container['projectMetadataModel']->save($pid, [
            'timeinvoice:defaults' => json_encode([
                'rate'       => 165.5,
                'terms_days' => 15,
                'currency'   => ['code' => 'GBP', 'symbol' => 'GBP'],
                'client'     => ['name' => 'Acme Inc', 'address' => '100 Road', 'email' => 'ap@acme.example'],
            ]),
        ]);

        $this->migrate();

        $settings = (new \Kanboard\Plugin\TimeInvoice\Model\ProjectSettingsModel($this->container))->get($pid);
        $this->assertSame(15, $settings['terms_days']);
        $this->assertSame('GBP', $settings['currency']['code']);
        $this->assertSame('ap@acme.example', $settings['client']['email']);
    }

    public function testMigrationIsSafeOnAnInstallWithNoData(): void
    {
        $this->migrate();

        $model = new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($this->container);
        $this->assertSame([], $model->listAll([1]));
        $this->assertSame(0.0, $model->outstandingTotal([1]));
    }

    /** A deleted project must not leave orphaned invoices behind. */
    public function testInvoicesCascadeWhenTheProjectIsDeleted(): void
    {
        $this->migrate();
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'Doomed']);
        $model = new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($this->container);
        $model->createDraft($pid, 1, ['range' => ['start' => '2026-08-01', 'end' => '2026-08-31'], 'granularity' => 'task', 'rate' => 10.0]);
        $this->assertCount(1, $model->listByProject($pid));

        $this->container['db']->getConnection()->exec('PRAGMA foreign_keys = ON');
        (new \Kanboard\Model\ProjectModel($this->container))->remove($pid);

        $this->assertSame([], $model->listByProject($pid), 'invoices are removed with their project');
    }
}
