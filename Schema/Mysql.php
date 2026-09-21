<?php

namespace Kanboard\Plugin\TimeInvoice\Schema;

use PDO;

const VERSION = 1;

/**
 * Move invoice records out of project_has_metadata.
 *
 * That column is VARCHAR(255) on MySQL and Postgres, but an issued invoice
 * serializes to ~1KB, so every write failed with SQLSTATE[22001] — or
 * truncated silently outside strict mode. SQLite ignores VARCHAR lengths,
 * which is why the plugin appeared to work there.
 *
 * Existing rows are COPIED, not moved: the originals stay in
 * project_has_metadata so downgrading to 1.2.1 still finds its data.
 */
function version_1(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE timeinvoice_invoices (
            id VARCHAR(64) NOT NULL,
            project_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            created_at VARCHAR(20) NOT NULL DEFAULT '',
            record MEDIUMTEXT NOT NULL,
            PRIMARY KEY(id),
            FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB CHARSET=utf8mb4
    ");
    $pdo->exec("CREATE INDEX timeinvoice_invoices_project_idx ON timeinvoice_invoices(project_id)");

    $pdo->exec("
        CREATE TABLE timeinvoice_project_settings (
            project_id INT NOT NULL,
            settings MEDIUMTEXT NOT NULL,
            PRIMARY KEY(project_id),
            FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB CHARSET=utf8mb4
    ");

    migrate_from_metadata($pdo);
    migrate_project_settings($pdo);
}

/**
 * Copy `timeinvoice:defaults` metadata rows into the new table. Same VARCHAR(255)
 * problem: a realistic client address pushes this blob past the limit too.
 */
function migrate_project_settings(PDO $pdo)
{
    $rows = $pdo
        ->query("SELECT project_id, value FROM project_has_metadata WHERE name = 'timeinvoice:defaults'")
        ->fetchAll(PDO::FETCH_ASSOC);

    $insert = $pdo->prepare('INSERT INTO timeinvoice_project_settings (project_id, settings) VALUES (?, ?)');

    foreach ($rows as $row) {
        if (! is_array(json_decode((string) $row['value'], true))) {
            continue; // truncated or corrupt
        }
        $insert->execute([(int) $row['project_id'], (string) $row['value']]);
    }
}

/**
 * Copy `timeinvoice:inv:*` metadata rows into the new table.
 *
 * Rows that no longer decode are skipped rather than failing the migration:
 * on MySQL they were truncated mid-JSON by the very bug this fixes, and a
 * migration that throws would leave the plugin unable to enable at all.
 */
function migrate_from_metadata(PDO $pdo)
{
    $rows = $pdo
        ->query("SELECT project_id, name, value FROM project_has_metadata WHERE name LIKE 'timeinvoice:inv:%'")
        ->fetchAll(PDO::FETCH_ASSOC);

    $insert = $pdo->prepare(
        'INSERT INTO timeinvoice_invoices (id, project_id, status, created_at, record) VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($rows as $row) {
        $record = json_decode((string) $row['value'], true);
        if (! is_array($record) || empty($record['id'])) {
            continue; // truncated or corrupt — unrecoverable, and not worth bricking the install over
        }

        $insert->execute([
            (string) $record['id'],
            (int) $row['project_id'],
            (string) ($record['status'] ?? 'draft'),
            (string) ($record['created_at'] ?? ''),
            (string) $row['value'],
        ]);
    }
}
