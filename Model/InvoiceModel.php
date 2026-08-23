<?php

namespace Kanboard\Plugin\TimeInvoice\Model;

use Kanboard\Core\Base;

/**
 * Persistence + lifecycle for invoices. Records are JSON blobs in
 * project_has_metadata keyed timeinvoice:inv:<id>. The per-year counter and the
 * number format live in the settings table via ConfigModel. No DB migration.
 */
class InvoiceModel extends Base
{
    private const KEY_PREFIX      = 'timeinvoice:inv:';
    private const CFG_COUNTER     = 'timeinvoice_counter';
    private const CFG_NUMBER_FMT  = 'timeinvoice_number_format';
    private const DEFAULT_FORMAT  = 'INV-{YYYY}-{seq}';

    public function createDraft(int $projectId, int $userId, array $draft): string
    {
        $id = (! empty($draft['id']) && is_string($draft['id'])) ? $draft['id'] : $this->newId();
        $record = array_merge($draft, [
            'id'         => $id,
            'project_id' => $projectId,
            'user_id'    => $userId,
            'status'     => 'draft',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->put($projectId, $id, $record);
        return $id;
    }

    public function load(int $projectId, string $id): ?array
    {
        $raw = $this->projectMetadataModel->get($projectId, self::KEY_PREFIX . $id, '');
        if ($raw === '' || $raw === null) {
            return null;
        }
        $rec = json_decode($raw, true);
        return is_array($rec) ? $rec : null;
    }

    public function listByProject(int $projectId): array
    {
        $rows = $this->db->table('project_has_metadata')
            ->eq('project_id', $projectId)
            ->like('name', self::KEY_PREFIX . '%')
            ->findAll();
        return $this->decodeRows($rows);
    }

    public function listAll(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }
        $rows = $this->db->table('project_has_metadata')
            ->in('project_id', array_map('intval', $projectIds))
            ->like('name', self::KEY_PREFIX . '%')
            ->findAll();
        return $this->decodeRows($rows);
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
        $this->projectMetadataModel->remove($projectId, self::KEY_PREFIX . $id);
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

    private function put(int $projectId, string $id, array $record): void
    {
        $this->projectMetadataModel->save($projectId, [self::KEY_PREFIX . $id => json_encode($record, JSON_PRESERVE_ZERO_FRACTION)]);
    }

    /** @return list<array> newest first */
    private function decodeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $rec = json_decode($row['value'], true);
            if (is_array($rec)) {
                $out[] = $rec;
            }
        }
        usort($out, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return $out;
    }

    /** Sortable, collision-resistant id (no Date.now/rand constraints — this is PHP runtime). */
    private function newId(): string
    {
        return date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
