<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Model\CoverNoteGenerator;

/** Test subclass: overrides the finder-backed gather step with fixed records. */
class CoverNoteGeneratorForTest extends CoverNoteGenerator
{
    public array $gathered = [
        ['title' => 'Homepage', 'description' => 'Rebuilt hero.',
         'subtasks' => [['title' => 'Hero', 'done' => true]],
         'comments' => [['text' => 'Approved.']]],
    ];
    public array $sawTaskIds = [];

    protected function gatherTasks(array $taskIds, int $projectId): array
    {
        $this->sawTaskIds = $taskIds;
        return $this->gathered;
    }
}

/** Fake AiConnector ProviderRegistry — records the messages, returns a canned note. */
class FakeRegistry
{
    public array $messages = [];
    public string $schema = '';
    public function structured(array $messages, string $schema, ?string $profileId): array
    {
        $this->messages = $messages;
        $this->schema = $schema;
        return ['cover_note' => 'We rebuilt the homepage hero this period.'];
    }
}

/** Fake ModelCatalog — fixed budget + a chars/4 counter. */
class FakeCatalog
{
    public function getInputBudget(?string $profileId = null, int $reserve = 1024): int { return 100000; }
    public function countTokens(string $text, ?string $profileId = null): int { return (int) ceil(mb_strlen($text) / 4); }
}

class CoverNoteGeneratorTest extends Base
{
    private function ctx(): array
    {
        return [
            'project_id' => 5, 'user_id' => 1,
            'start' => '2026-08-01', 'end' => '2026-08-31', 'granularity' => 'task',
            'rate' => 150.0, 'tax_enabled' => false, 'tax_rate' => 0.0,
            'project_name' => 'Acme', 'client' => ['name' => 'Acme Inc'],
            'currency' => ['code' => 'USD', 'symbol' => '$'],
            'style' => '', 'profile_id' => null,
        ];
    }

    private function withReport(array $breakdown): void
    {
        $this->container['timeReportModel'] = fn ($c) => new class($breakdown) {
            public function __construct(private array $b) {}
            public function report($pid, $s, $e, $g, $d, $u) { return ['breakdown' => $this->b]; }
        };
    }

    public function testGathersUnionOfBreakdownTaskIds(): void
    {
        $this->withReport([
            ['key' => 'a', 'label' => '#1', 'hours' => 5.0, 'task_count' => 1, 'task_ids' => [11, 12]],
            ['key' => 'b', 'label' => '#2', 'hours' => 2.0, 'task_count' => 1, 'task_ids' => [12, 13]],
        ]);
        $gen = new CoverNoteGeneratorForTest($this->container);
        $note = $gen->generate($this->ctx(), new FakeRegistry(), new FakeCatalog());
        $this->assertSame('We rebuilt the homepage hero this period.', $note);
        sort($gen->sawTaskIds);
        $this->assertSame([11, 12, 13], $gen->sawTaskIds, 'deduped union of breakdown[].task_ids');
    }

    public function testSystemPromptCarriesStyleAndPayloadCarriesContext(): void
    {
        $this->withReport([['key' => 'a', 'label' => '#1', 'hours' => 5.0, 'task_count' => 1, 'task_ids' => [11]]]);
        $reg = new FakeRegistry();
        $ctx = $this->ctx();
        $ctx['style'] = 'Terse and formal.';
        $gen = new CoverNoteGeneratorForTest($this->container);
        $gen->generate($ctx, $reg, new FakeCatalog());
        $this->assertSame('system', $reg->messages[0]['role']);
        $this->assertStringContainsString('Terse and formal.', $reg->messages[0]['content']);
        $this->assertSame('user', $reg->messages[1]['role']);
        $this->assertStringContainsString('INVOICE CONTEXT', $reg->messages[1]['content']);
        $this->assertStringContainsString('Rebuilt hero.', $reg->messages[1]['content']);
        $this->assertStringContainsString('cover_note', $reg->schema);
    }

    public function testDefaultStyleUsedWhenBlank(): void
    {
        $prompt = CoverNoteGenerator::systemPrompt('');
        $this->assertStringContainsString(CoverNoteGenerator::DEFAULT_STYLE, $prompt);
    }

    public function testReturnsNullWhenModelYieldsEmpty(): void
    {
        $this->withReport([['key' => 'a', 'label' => '#1', 'hours' => 5.0, 'task_count' => 1, 'task_ids' => [11]]]);
        $emptyReg = new class {
            public function structured(array $m, string $s, ?string $p): array { return ['cover_note' => '   ']; }
        };
        $gen = new CoverNoteGeneratorForTest($this->container);
        $this->assertNull($gen->generate($this->ctx(), $emptyReg, new FakeCatalog()));
    }
}
