<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;

class TemplateAssetsTest extends Base
{
    private function root(): string { return dirname(__DIR__); }

    public function testReferencedAssetsExist(): void
    {
        foreach (['Assets/css/timeinvoice.css', 'Assets/js/timeinvoice.js',
                  'Template/invoice/sidebar.php', 'Template/invoice/header_dropdown.php'] as $rel) {
            $this->assertFileExists($this->root() . '/' . $rel, $rel);
        }
    }

    public function testNoInlineHandlersInTemplates(): void
    {
        foreach (glob($this->root() . '/Template/invoice/*.php') as $tpl) {
            $src = file_get_contents($tpl);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*"/i', $src, "inline handler in $tpl");
            $this->assertStringNotContainsString('<script', $src, "inline <script> in $tpl");
        }
    }

    public function testNoUnescapedPercentInSingleArgTranslations(): void
    {
        foreach (glob($this->root() . '/Template/invoice/*.php') as $tpl) {
            $src = file_get_contents($tpl);
            // single-arg t('...') calls: no comma between the string and the closing paren
            preg_match_all("/t\\('((?:[^'\\\\]|\\\\.)*)'\\s*\\)/", $src, $m);
            foreach ($m[1] as $str) {
                $stripped = str_replace('%%', '', $str);
                $this->assertStringNotContainsString('%', $stripped, "unescaped % in t('$str') in $tpl (breaks sprintf at render)");
            }
        }
    }
}
