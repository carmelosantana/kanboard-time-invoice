<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Plugin;

class PluginTest extends Base
{
    public function testMetadata(): void
    {
        $p = new Plugin($this->container);
        $this->assertSame('TimeInvoice', $p->getPluginName());
        // Release-agnostic: testVersionMatchesJson() pins plugin.json and
        // Plugin::getPluginVersion() to each other, so this only pins the shape.
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $p->getPluginVersion());
        $this->assertSame('Carmelo Santana', $p->getPluginAuthor());
        $this->assertSame('MIT', $p->getPluginLicense());
        $this->assertSame('>=1.2.47', $p->getCompatibleVersion());
        $this->assertNotEmpty($p->getPluginDescription());
        $this->assertStringContainsString('github.com/carmelosantana/kanboard-time-invoice', $p->getPluginHomepage());
    }

    public function testVersionMatchesJson(): void
    {
        $json = json_decode(file_get_contents(dirname(__DIR__) . '/plugin.json'), true);
        $this->assertSame($json['version'], (new Plugin($this->container))->getPluginVersion());
    }

    public function testPhpGate(): void
    {
        $p = new Plugin($this->container);
        $this->assertTrue($p->isPhpCompatible(80400));
        $this->assertFalse($p->isPhpCompatible(80300));
    }

    public function testRequiresGateReflectsContainer(): void
    {
        $p = new Plugin($this->container);
        $this->assertFalse($p->requiresTimeReport(), 'no timeReportModel registered in bare container');
        $this->container['timeReportModel'] = fn ($c) => new \stdClass();
        $this->assertTrue($p->requiresTimeReport());
    }
}
