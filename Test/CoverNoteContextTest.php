<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Model\CoverNoteContext;

class CoverNoteContextTest extends Base
{
    /** ~4 chars per token, matching AiConnector's heuristic order of magnitude. */
    private function counter(): callable
    {
        return fn (string $s): int => (int) ceil(mb_strlen($s) / 4);
    }

    private function header(): array
    {
        return [
            'project' => 'Acme Redesign', 'client' => 'Acme Inc',
            'range' => ['start' => '2026-08-01', 'end' => '2026-08-31'], 'granularity' => 'task',
            'line_items' => [['label' => '#1 Homepage', 'hours' => 8.5], ['label' => '#2 API', 'hours' => 3.0]],
            'subtotal' => 1725.0, 'tax' => ['enabled' => true, 'rate' => 10.0, 'amount' => 172.5],
            'total' => 1897.5, 'currency' => ['code' => 'USD', 'symbol' => '$'],
        ];
    }

    private function tasks(): array
    {
        return [
            ['title' => 'Homepage', 'description' => 'Rebuilt the homepage hero and nav.',
             'subtasks' => [['title' => 'Hero', 'done' => true], ['title' => 'Nav', 'done' => false]],
             'comments' => [['text' => 'Client approved the hero copy.']]],
            ['title' => 'API', 'description' => 'Added the reporting endpoint.',
             'subtasks' => [['title' => 'Schema', 'done' => true]],
             'comments' => [['text' => 'Deferred pagination to next sprint.']]],
        ];
    }

    public function testSkeletonAlwaysPresentEvenWithZeroBudget(): void
    {
        $out = CoverNoteContext::assemble($this->header(), $this->tasks(), 0, $this->counter());
        $this->assertStringContainsString('INVOICE CONTEXT', $out);
        $this->assertStringContainsString('Project: Acme Redesign', $out);
        $this->assertStringContainsString('#1 Homepage', $out);
        $this->assertStringContainsString('Total: $1,897.50', $out);
        // Zero budget: nothing beyond the skeleton.
        $this->assertStringNotContainsString('description:', $out);
    }

    public function testLargeBudgetIncludesEveryTier(): void
    {
        $out = CoverNoteContext::assemble($this->header(), $this->tasks(), 100000, $this->counter());
        $this->assertStringContainsString('Rebuilt the homepage hero', $out); // description
        $this->assertStringContainsString('Hero [done]', $out);               // subtask
        $this->assertStringContainsString('Client approved the hero copy', $out); // comment
    }

    public function testCommentsDropBeforeSubtasksUnderTightBudget(): void
    {
        // Budget that fits skeleton + descriptions + subtasks, but not comments.
        $c = $this->counter();
        $skeleton = CoverNoteContext::assemble($this->header(), [], 0, $c);
        $withSubs = CoverNoteContext::assemble($this->header(), $this->tasks(), 100000, $c);
        // Find a budget between "through subtasks" and "through comments".
        $full = $c($withSubs);
        $budget = $full - 3; // just short of the last comment block
        $out = CoverNoteContext::assemble($this->header(), $this->tasks(), $budget, $c);
        $this->assertStringContainsString('Hero [done]', $out, 'subtasks kept');
        $this->assertStringNotContainsString('Deferred pagination', $out, 'comments dropped first');
    }

    public function testSubtasksDropWhenOnlyDescriptionsFit(): void
    {
        $c = $this->counter();
        $skeletonOnly = CoverNoteContext::assemble($this->header(), [], 0, $c);
        // Budget = skeleton + first description block only.
        $firstDesc = "\nTask \"Homepage\" — description: Rebuilt the homepage hero and nav.";
        $budget = $c($skeletonOnly . $firstDesc);
        $out = CoverNoteContext::assemble($this->header(), $this->tasks(), $budget, $c);
        $this->assertStringContainsString('Rebuilt the homepage hero', $out);
        $this->assertStringNotContainsString('[done]', $out, 'subtasks dropped when only descriptions fit');
    }

    public function testEmptyTasksYieldSkeletonOnly(): void
    {
        $out = CoverNoteContext::assemble($this->header(), [], 100000, $this->counter());
        $this->assertStringContainsString('INVOICE CONTEXT', $out);
        $this->assertStringNotContainsString('description:', $out);
    }

    public function testTaxLineOmittedWhenDisabled(): void
    {
        $h = $this->header();
        $h['tax'] = ['enabled' => false, 'rate' => 0.0, 'amount' => 0.0];
        $out = CoverNoteContext::assemble($h, [], 0, $this->counter());
        $this->assertStringNotContainsString('Tax (', $out);
    }
}
