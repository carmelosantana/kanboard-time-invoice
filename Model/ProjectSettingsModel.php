<?php

namespace Kanboard\Plugin\TimeInvoice\Model;

use Kanboard\Core\Base;

/**
 * Per-project invoice defaults: rate, currency, payment terms, default notes
 * and the client block.
 *
 * Stored in the plugin's own table rather than `project_has_metadata`, whose
 * `value` column is VARCHAR(255) on MySQL and Postgres — a realistic client
 * address pushes this blob past that limit, which failed with SQLSTATE[22001]
 * or truncated silently. Single source of truth for both the controller that
 * writes it and InvoiceController, which layers it under the invoice form.
 */
class ProjectSettingsModel extends Base
{
    public const TABLE = 'timeinvoice_project_settings';

    /** @return array the decoded defaults, or [] when unset */
    public function get(int $projectId): array
    {
        $raw = $this->db->table(self::TABLE)->eq('project_id', $projectId)->findOneColumn('settings');
        if ($raw === null || $raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function save(int $projectId, array $settings): void
    {
        $values = ['settings' => json_encode($settings, JSON_PRESERVE_ZERO_FRACTION)];

        if ($this->db->table(self::TABLE)->eq('project_id', $projectId)->exists()) {
            $this->db->table(self::TABLE)->eq('project_id', $projectId)->update($values);
            return;
        }
        $this->db->table(self::TABLE)->insert($values + ['project_id' => $projectId]);
    }
}
