<?php

/**
 * Creates the plugin's tables in the test database by running the REAL schema
 * migration, so every test that touches storage also exercises the migration
 * rather than a hand-rolled copy that could drift from it.
 *
 * Not named *Test.php on purpose — PHPUnit collects that suffix only, so this
 * file is never mistaken for a test case.
 *
 * Only the SQLite variant is loaded: all three Schema files declare the same
 * namespace and function names (that is how Kanboard's SchemaHandler works —
 * it requires exactly one, chosen by driver), so requiring a second would be a
 * fatal duplicate-function error. The harness runs on SQLite.
 */
trait InvoiceSchemaHelper
{
    private function createInvoiceSchema(): void
    {
        require_once dirname(__DIR__) . '/Schema/Sqlite.php';

        $pdo = $this->container['db']->getConnection();
        $existing = $pdo
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='timeinvoice_invoices'")
            ->fetchColumn();

        if ($existing === false || $existing === null) {
            \Kanboard\Plugin\TimeInvoice\Schema\version_1($pdo);
        }
    }
}
