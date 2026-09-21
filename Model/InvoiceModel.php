<?php

namespace Kanboard\Plugin\TimeInvoice\Model;

use Kanboard\Core\Base;

/**
 * Persistence + lifecycle for invoices.
 *
 * Records live in the plugin's own `timeinvoice_invoices` table, whose `record`
 * column is MEDIUMTEXT/TEXT. They used to be JSON blobs in
 * `project_has_metadata`, but that column is VARCHAR(255) on MySQL and
 * Postgres while an issued invoice serializes to roughly 1KB — every write
 * failed with SQLSTATE[22001], or truncated silently outside MySQL strict
 * mode. SQLite ignores declared VARCHAR lengths, which is the only reason the
 * plugin ever appeared to work.
 *
 * `project_id`, `status` and `created_at` are promoted to real columns so
 * listing and sorting happen in SQL; the full record stays as JSON so the
 * frozen snapshot keeps its exact shape.
 *
 * The per-year counter and the number format still live in the settings table
 * via ConfigModel — `settings.value` is mediumtext, so those are unaffected.
 */
class InvoiceModel extends Base
{
    public const TABLE = 'timeinvoice_invoices';

    private const CFG_COUNTER     = 'timeinvoice_counter';
    private const CFG_NUMBER_FMT  = 'timeinvoice_number_format';
    private const DEFAULT_FORMAT  = 'INV-{YYYY}-{seq}';

    public function createDraft(int $projectId, int $userId, array $draft): string
    {
        $id = (! empty($draft['id']) && is_string($draft['id'])) ? $draft['id'] : $this->newId();
        $existing = $this->load($projectId, $id);

        $record = array_merge($draft, [
            'id'         => $id,
            'project_id' => $projectId,
            'user_id'    => $userId,
            'status'     => 'draft',
            // Preserve the original creation time when re-saving a draft, so
            // the newest-first ordering does not jump on every edit.
            'created_at' => $existing['created_at'] ?? date('Y-m-d H:i:s'),
        ]);

        $this->put($projectId, $id, $record);
        return $id;
    }

    public function load(int $projectId, string $id): ?array
    {
        $raw = $this->db->table(self::TABLE)
            ->eq('project_id', $projectId)
            ->eq('id', $id)
            ->findOneColumn('record');

        if ($raw === null || $raw === false || $raw === '') {
            return null;
        }
        $rec = json_decode($raw, true);
        return is_array($rec) ? $rec : null;
    }

    public function listByProject(int $projectId): array
    {
        return $this->decodeRows(
            $this->db->table(self::TABLE)
                ->eq('project_id', $projectId)
                ->desc('created_at')
                ->findAll()
        );
    }

    public function listAll(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }
        return $this->decodeRows(
            $this->db->table(self::TABLE)
                ->in('project_id', array_map('intval', $projectIds))
                ->desc('created_at')
                ->findAll()
        );
    }

    public function send(int $projectId, string $id, array $frozenSnapshot): array
    {
        $record = $this->load($projectId, $id);
        if ($record === null) {
            throw new \RuntimeException('Invoice not found');
        }
        $issueDate = $frozenSnapshot['issue_date'] ?? date('Y-m-d');

        $counter = json_decode($this->configModel->get(self::CFG_COUNTER, '{}'), true) ?: [];
        $format  = $this->configModel->get(self::CFG_NUMBER_FMT, self::DEFAULT_FORMAT) ?: self::DEFAULT_FORMAT;
        $next    = InvoiceNumber::next($counter, $issueDate, $format);
        $this->configModel->save([self::CFG_COUNTER => json_encode($next['counter'])]);

        $record = array_merge($record, $frozenSnapshot, [
            'status'  => 'sent',
            'number'  => $next['number'],
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
        $this->put($projectId, $id, $record);
        return $record;
    }

    public function markPaid(int $projectId, string $id): void
    {
        $record = $this->load($projectId, $id);
        if ($record === null) {
            return;
        }
        $record['status'] = 'paid';
        $record['paid_at'] = date('Y-m-d H:i:s');
        $this->put($projectId, $id, $record);
    }

    public function delete(int $projectId, string $id): void
    {
        $this->db->table(self::TABLE)->eq('project_id', $projectId)->eq('id', $id)->remove();
    }

    public function outstandingTotal(array $projectIds): float
    {
        $sum = 0.0;
        foreach ($this->listAll($projectIds) as $rec) {
            if (($rec['status'] ?? '') === 'sent') {
                $sum += (float) ($rec['total'] ?? 0.0);
            }
        }
        return round($sum, 2);
    }

    /** Insert or update the row for this invoice. */
    private function put(int $projectId, string $id, array $record): void
    {
        $values = [
            'project_id' => $projectId,
            'status'     => (string) ($record['status'] ?? 'draft'),
            'created_at' => (string) ($record['created_at'] ?? date('Y-m-d H:i:s')),
            'record'     => json_encode($record, JSON_PRESERVE_ZERO_FRACTION),
        ];

        $exists = $this->db->table(self::TABLE)->eq('project_id', $projectId)->eq('id', $id)->exists();

        if ($exists) {
            $this->db->table(self::TABLE)->eq('project_id', $projectId)->eq('id', $id)->update($values);
            return;
        }
        $this->db->table(self::TABLE)->insert($values + ['id' => $id]);
    }

    /** @return list<array> newest first */
    private function decodeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $rec = json_decode($row['record'], true);
            if (is_array($rec)) {
                $out[] = $rec;
            }
        }
        // The SQL ordering handles the common case; this keeps the exact
        // newest-first contract even when created_at values collide.
        usort($out, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return $out;
    }

    /** Sortable, collision-resistant id (no Date.now/rand constraints — this is PHP runtime). */
    private function newId(): string
    {
        return date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
