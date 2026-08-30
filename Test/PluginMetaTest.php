<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;

class PluginMetaTest extends Base
{
    private function json(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/plugin.json'), true);
    }

    public function testVersionIs110(): void
    {
        $this->assertSame('1.1.0', $this->json()['version']);
    }

    public function testNameAndCompat(): void
    {
        $j = $this->json();
        $this->assertSame('TimeInvoice', $j['name']);
        $this->assertSame('>=1.2.47', $j['kanboard_version']);
        $this->assertSame('>=8.4', $j['php_version']);
        $this->assertSame('MIT', $j['license']);
    }

    /** requires must be an ARRAY of objects with a bare min_version (no ">=" prefix). */
    public function testRequiresArrayShape(): void
    {
        $j = $this->json();
        $this->assertSame(['TimeReport'], array_column($j['requires'], 'plugin'));
        $this->assertSame('1.4.0', $j['requires'][0]['min_version']);
        $this->assertStringStartsNotWith('>=', $j['requires'][0]['min_version']);
        $this->assertNotEmpty($j['requires'][0]['reason']);
    }

    /** recommends: AiConnector, bare min_version, same array-of-objects shape. */
    public function testRecommendsAiConnectorShape(): void
    {
        $j = $this->json();
        $this->assertArrayHasKey('recommends', $j, 'v1.1.0 recommends AiConnector');
        $this->assertSame(['AiConnector'], array_column($j['recommends'], 'plugin'));
        $this->assertSame('1.1.0', $j['recommends'][0]['min_version']);
        $this->assertStringStartsNotWith('>=', $j['recommends'][0]['min_version']);
        $this->assertNotEmpty($j['recommends'][0]['reason']);
    }

    public function testPluginPhpVersionMatchesJson(): void
    {
        $plugin = new \Kanboard\Plugin\TimeInvoice\Plugin($this->container);
        $this->assertSame($this->json()['version'], $plugin->getPluginVersion());
    }
}
