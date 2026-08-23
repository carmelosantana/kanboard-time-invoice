<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;

class PluginMetaTest extends Base
{
    private function json(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/plugin.json'), true);
    }

    public function testVersionIs010(): void
    {
        $this->assertSame('0.1.0', $this->json()['version']);
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
        $this->assertArrayNotHasKey('recommends', $j, 'v1 declares no recommends');
        $this->assertSame(['TimeReport'], array_column($j['requires'], 'plugin'));
        $this->assertSame('1.1.0', $j['requires'][0]['min_version']);
        $this->assertStringStartsNotWith('>=', $j['requires'][0]['min_version']);
        $this->assertNotEmpty($j['requires'][0]['reason']);
    }
}
