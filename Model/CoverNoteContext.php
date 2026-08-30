<?php

namespace Kanboard\Plugin\TimeInvoice\Model;

/**
 * Pure, budget-aware context assembler for the AI cover note. No DB, no
 * framework. Tiers the payload by value (skeleton → descriptions → subtasks →
 * comments) and greedily adds whole units while they fit the token budget,
 * stopping entirely at the first unit that does not fit. The skeleton is always
 * present, even when it alone exceeds the budget.
 */
class CoverNoteContext
{
    /**
     * @param array    $header      project/client/range/line_items/totals/currency
     * @param array    $tasks       list of {title,description,subtasks[],comments[]}
     * @param int      $budget      max tokens for the whole payload
     * @param callable $countTokens fn(string): int
     */
    public static function assemble(array $header, array $tasks, int $budget, callable $countTokens): string
    {
        $payload = self::skeleton($header);

        // Tier 2 — descriptions.
        foreach ($tasks as $t) {
            $desc = self::flat((string) ($t['description'] ?? ''));
            if ($desc === '') {
                continue;
            }
            $block = "\nTask \"" . ($t['title'] ?? '') . "\" — description: " . $desc;
            if ($countTokens($payload . $block) <= $budget) {
                $payload .= $block;
            } else {
                return $payload;
            }
        }

        // Tier 3 — subtasks (title + done-state).
        foreach ($tasks as $t) {
            if (empty($t['subtasks'])) {
                continue;
            }
            $parts = array_map(
                fn ($s) => ($s['title'] ?? '') . (! empty($s['done']) ? ' [done]' : ' [open]'),
                $t['subtasks']
            );
            $block = "\nTask \"" . ($t['title'] ?? '') . "\" — subtasks: " . implode('; ', $parts);
            if ($countTokens($payload . $block) <= $budget) {
                $payload .= $block;
            } else {
                return $payload;
            }
        }

        // Tier 4 — comments (first to drop on small models).
        foreach ($tasks as $t) {
            if (empty($t['comments'])) {
                continue;
            }
            $parts = array_map(fn ($c) => self::flat((string) ($c['text'] ?? '')), $t['comments']);
            $block = "\nTask \"" . ($t['title'] ?? '') . "\" — comments: " . implode(' | ', $parts);
            if ($countTokens($payload . $block) <= $budget) {
                $payload .= $block;
            } else {
                return $payload;
            }
        }

        return $payload;
    }

    private static function skeleton(array $h): string
    {
        $sym = $h['currency']['symbol'] ?? '$';
        $lines = [
            'INVOICE CONTEXT',
            'Project: ' . ($h['project'] ?? ''),
            'Client: ' . ($h['client'] ?? ''),
            'Period: ' . ($h['range']['start'] ?? '') . ' to ' . ($h['range']['end'] ?? '')
                . ' (' . ($h['granularity'] ?? '') . ')',
            'Line items:',
        ];
        foreach ($h['line_items'] ?? [] as $li) {
            $hours = rtrim(rtrim(number_format((float) ($li['hours'] ?? 0), 2), '0'), '.');
            $lines[] = '- ' . ($li['label'] ?? '') . ': ' . $hours . 'h';
        }
        $lines[] = 'Subtotal: ' . $sym . number_format((float) ($h['subtotal'] ?? 0), 2);
        if (! empty($h['tax']['enabled'])) {
            $lines[] = 'Tax (' . (float) ($h['tax']['rate'] ?? 0) . '%): '
                . $sym . number_format((float) ($h['tax']['amount'] ?? 0), 2);
        }
        $lines[] = 'Total: ' . $sym . number_format((float) ($h['total'] ?? 0), 2);
        return implode("\n", $lines);
    }

    /** Collapse runs of whitespace/newlines to single spaces and trim. */
    private static function flat(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }
}
