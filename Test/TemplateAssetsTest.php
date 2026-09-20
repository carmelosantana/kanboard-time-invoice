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

    public function testFormHasAiBlockGatedOnAiReady(): void
    {
        $src = file_get_contents($this->root() . '/Template/invoice/form.php');
        $this->assertStringContainsString('ai_ready', $src, 'AI block must be gated on ai_ready');
        $this->assertStringContainsString('timeinvoice-generate-note', $src, 'generate button present');
        $this->assertStringContainsString('timeinvoice-profile', $src, 'profile select present');
        $this->assertStringContainsString('id="form-profile_id"', $src, 'profile select id matches its label for=');
        $this->assertStringContainsString("'generateCoverNote'", $src, 'button targets the generate action');
    }

    public function testJsHasGenerateHandler(): void
    {
        $src = file_get_contents($this->root() . '/Assets/js/timeinvoice.js');
        $this->assertStringContainsString('timeinvoice-generate-note', $src);
        $this->assertStringContainsString("name='notes'", $src, 'handler writes into the notes textarea');
    }

    public function testConfigSidebarPartialExistsAndLinks(): void
    {
        $path = $this->root() . '/Template/config/sidebar.php';
        $this->assertFileExists($path);
        $src = file_get_contents($path);
        $this->assertStringContainsString("'SettingsController'", $src);
        $this->assertStringContainsString("'TimeInvoice'", $src);
    }

    public function testPluginRegistersConfigSidebarHook(): void
    {
        $src = file_get_contents($this->root() . '/Plugin.php');
        $this->assertStringContainsString('template:config:sidebar', $src, 'settings page must be linked from the admin sidebar');
        $this->assertStringContainsString('TimeInvoice:config/sidebar', $src);
    }

    public function testFormLabelsClientAsInheritedFromProject(): void
    {
        $src = file_get_contents($this->root() . '/Template/invoice/form.php');
        $this->assertStringContainsString('Inherited from the project', $src, 'the client block must say where its values come from');
    }

    public function testShowTemplateExistsAndCarriesActions(): void
    {
        $path = $this->root() . '/Template/invoice/show.php';
        $this->assertFileExists($path);
        $src = file_get_contents($path);
        foreach (["'pdf'", "'form'", "'send'", "'markPaid'", "'delete'"] as $action) {
            $this->assertStringContainsString($action, $src, "show page must offer $action");
        }
    }

    public function testListRowCollapsesToShowPage(): void
    {
        $src = file_get_contents($this->root() . '/Template/invoice/list.php');
        $this->assertStringContainsString("'show'", $src, 'the number must link to the show page');
        foreach (["'markPaid'", "'delete'", "'send'"] as $action) {
            $this->assertStringNotContainsString($action, $src, "$action must live on the show page, not in a list row");
        }
    }

    public function testNoSendWordingRemainsInUi(): void
    {
        $js = file_get_contents($this->root() . '/Assets/js/timeinvoice.js');
        $this->assertStringNotContainsString('Send this invoice?', $js, 'confirm copy must not promise an email');
        $this->assertStringContainsString('Issue this invoice?', $js);
    }
}
