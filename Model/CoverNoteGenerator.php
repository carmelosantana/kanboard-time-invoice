<?php

namespace Kanboard\Plugin\TimeInvoice\Model;

use Kanboard\Core\Base;

/**
 * Orchestrates AI cover-note generation: gather the billed tasks (via
 * TimeReport's breakdown[].task_ids + Kanboard finders), size the prompt to the
 * model's context window (AiConnector ModelCatalog), assemble tiered context
 * (CoverNoteContext), and generate a structured note (AiConnector
 * ProviderRegistry). The AiConnector objects are passed in — the caller
 * constructs them only behind the aiReady() gate.
 */
class CoverNoteGenerator extends Base
{
    public const OUTPUT_RESERVE = 512;
    public const SCHEMA = '{"type":"object","properties":{"cover_note":{"type":"string"}},"required":["cover_note"]}';
    public const DEFAULT_STYLE = 'Professional and concise, first-person plural ("we"), focused on outcomes delivered.';

    /**
     * @param array  $ctx      generation context (see plan Task 3 interface)
     * @param object $registry AiConnector ProviderRegistry (has structured())
     * @param object $catalog  AiConnector ModelCatalog (has getInputBudget()/countTokens())
     */
    public function generate(array $ctx, object $registry, object $catalog): ?string
    {
        $projectId   = (int) $ctx['project_id'];
        $userId      = (int) $ctx['user_id'];
        $granularity = (string) $ctx['granularity'];
        $rate        = (float) $ctx['rate'];
        $profileId   = $ctx['profile_id'] ?? null;

        $report    = $this->timeReportModel->report($projectId, $ctx['start'], $ctx['end'], $granularity, true, $userId);
        $breakdown = $report['breakdown'] ?? [];

        $taskIds = [];
        foreach ($breakdown as $row) {
            foreach (($row['task_ids'] ?? []) as $tid) {
                $taskIds[(int) $tid] = true;
            }
        }
        $taskIds = array_keys($taskIds);

        $items  = InvoiceBuilder::lineItems($breakdown, $rate);
        $totals = InvoiceBuilder::totals($items, ! empty($ctx['tax_enabled']), (float) $ctx['tax_rate']);
        $tasks  = $this->gatherTasks($taskIds, $projectId);

        $header = [
            'project'     => (string) ($ctx['project_name'] ?? ''),
            'client'      => (string) ($ctx['client']['name'] ?? ''),
            'range'       => ['start' => $ctx['start'], 'end' => $ctx['end']],
            'granularity' => $granularity,
            'line_items'  => $items,
            'subtotal'    => $totals['subtotal'],
            'tax'         => ['enabled' => ! empty($ctx['tax_enabled']), 'rate' => (float) $ctx['tax_rate'], 'amount' => $totals['tax']],
            'total'       => $totals['total'],
            'currency'    => $ctx['currency'] ?? ['code' => 'USD', 'symbol' => '$'],
        ];

        $system = self::systemPrompt((string) ($ctx['style'] ?? ''));
        $budget = max(0, $catalog->getInputBudget($profileId, self::OUTPUT_RESERVE) - $catalog->countTokens($system, $profileId));
        $payload = CoverNoteContext::assemble($header, $tasks, $budget, fn (string $s): int => $catalog->countTokens($s, $profileId));

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $payload],
        ];
        $result = $registry->structured($messages, self::SCHEMA, $profileId);
        $note   = trim((string) ($result['cover_note'] ?? ''));

        return $note !== '' ? $note : null;
    }

    public static function systemPrompt(string $style): string
    {
        $style = trim($style) !== '' ? trim($style) : self::DEFAULT_STYLE;
        return 'You write concise, professional invoice cover notes for a solo consultant. '
            . 'Style: ' . $style . ' '
            . 'Summarize only the work described in the provided context. Do not invent work that is not present. '
            . 'Write 2 to 4 sentences of client-facing prose. Return only the cover note text.';
    }

    /**
     * Fetch title/description/subtasks/comments for the billed task ids, scoped to
     * the invoice's project. Protected so tests can substitute fixed records.
     *
     * @return list<array{title:string,description:string,subtasks:list<array{title:string,done:bool}>,comments:list<array{text:string}>}>
     */
    protected function gatherTasks(array $taskIds, int $projectId): array
    {
        $out = [];
        foreach ($taskIds as $tid) {
            $task = $this->taskFinderModel->getById((int) $tid);
            if (empty($task) || (int) ($task['project_id'] ?? 0) !== $projectId) {
                continue;
            }
            $subs = $this->subtaskModel->getAll((int) $tid);
            $comments = $this->commentModel->getAll((int) $tid);
            $out[] = [
                'title'       => (string) ($task['title'] ?? ''),
                'description' => (string) ($task['description'] ?? ''),
                'subtasks'    => array_map(
                    fn ($s) => ['title' => (string) ($s['title'] ?? ''), 'done' => ((int) ($s['status'] ?? 0)) === 2],
                    $subs
                ),
                'comments'    => array_map(fn ($c) => ['text' => (string) ($c['comment'] ?? '')], $comments),
            ];
        }
        return $out;
    }
}
