<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;

/**
 * Renders every plugin template through Kanboard's real template engine.
 *
 * The other template tests grep source text; these actually execute the PHP,
 * so they catch the class of bug greps cannot: an undefined variable a
 * controller forgot to pass, a helper that does not exist, a bad array access.
 * Every param list below mirrors what the controller really supplies.
 */
class TemplateRenderTest extends Base
{
    private bool $helperRegistered = false;

    private function render(string $name, array $params): string
    {
        // Pimple freezes a service once resolved, so register exactly once.
        if (! $this->helperRegistered) {
            $this->container['helper']->register('invoice', \Kanboard\Plugin\TimeInvoice\Helper\InvoiceHelper::class);
            $this->helperRegistered = true;
        }
        return $this->container['template']->render($name, $params);
    }

    private function snapshot(): array
    {
        return [
            'issue_date' => '2026-09-01',
            'due_date'   => '2026-09-21',
            'range'      => ['start' => '2026-08-01', 'end' => '2026-08-31'],
            'currency'   => ['code' => 'USD', 'symbol' => '$'],
            'business'   => ['name' => 'CS Consulting', 'address' => "1 Road\nTown", 'email' => 'me@example.com'],
            'client'     => ['name' => 'Acme Inc', 'address' => '2 Street', 'email' => 'ap@acme.example'],
            'rate'       => 150.0,
            'line_items' => [['label' => '#1 Migration', 'hours' => 4.0, 'amount' => 600.0]],
            'subtotal'   => 600.0,
            'tax'        => ['enabled' => true, 'rate' => 10.0, 'amount' => 60.0],
            'total'      => 660.0,
            'notes'      => 'Thanks!',
            'number'     => 'INV-2026-001',
        ];
    }

    private function silent(): array
    {
        return ['visible' => false, 'specific' => false, 'people' => 0, 'hours' => 0.0];
    }

    public function testShowRendersAnIssuedInvoice(): void
    {
        $html = $this->render('TimeInvoice:invoice/show', [
            'project'    => ['id' => 1, 'name' => 'Acme Retainer'],
            'invoice'    => $this->snapshot(),
            'status'     => 'sent',
            'invoice_id' => 'abc',
            'unbilled'   => $this->silent(),
        ]);

        $this->assertStringContainsString('INV-2026-001', $html);
        $this->assertStringContainsString('Issued', $html, 'the sent status must read as Issued');
        $this->assertStringContainsString('#1 Migration', $html);
        $this->assertStringContainsString('$660.00', $html, 'grand total is rendered');
        $this->assertStringContainsString('Mark paid', $html, 'an issued invoice offers Mark paid');
        $this->assertStringNotContainsString('Delete', $html, 'an issued invoice must not offer Delete');
    }

    public function testShowRendersADraftWithItsOwnActions(): void
    {
        $snap = $this->snapshot();
        $snap['number'] = null;
        $html = $this->render('TimeInvoice:invoice/show', [
            'project'    => ['id' => 1, 'name' => 'P'],
            'invoice'    => $snap,
            'status'     => 'draft',
            'invoice_id' => 'abc',
            'unbilled'   => $this->silent(),
        ]);

        $this->assertStringContainsString('(draft)', $html);
        $this->assertStringContainsString('Issue', $html);
        $this->assertStringContainsString('Delete', $html);
        $this->assertStringContainsString('View PDF', $html);
        $this->assertStringNotContainsString('Mark paid', $html, 'a draft cannot be marked paid');
    }

    public function testShowRendersTheUnbilledBannerInBothForms(): void
    {
        $specific = $this->render('TimeInvoice:invoice/show', [
            'project' => ['id' => 1, 'name' => 'P'], 'invoice' => $this->snapshot(),
            'status' => 'draft', 'invoice_id' => 'a',
            'unbilled' => ['visible' => true, 'specific' => true, 'people' => 3, 'hours' => 22.5],
        ]);
        $this->assertStringContainsString('22.50', $specific);
        $this->assertStringContainsString('3 other people', $specific);

        $generic = $this->render('TimeInvoice:invoice/show', [
            'project' => ['id' => 1, 'name' => 'P'], 'invoice' => $this->snapshot(),
            'status' => 'draft', 'invoice_id' => 'a',
            'unbilled' => ['visible' => true, 'specific' => false, 'people' => 0, 'hours' => 0.0],
        ]);
        $this->assertStringContainsString('may not be seeing all billable hours', $generic);
        $this->assertStringNotContainsString('other people logged', $generic, 'a non-manager must not see counts');
    }

    public function testFormRendersWithTotalsRegionAndTermsField(): void
    {
        $html = $this->render('TimeInvoice:invoice/form', [
            'project'  => ['id' => 1, 'name' => 'P'],
            'values'   => [
                'project_id' => 1, 'id' => '', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
                'granularity' => 'task', 'rate' => 150.0, 'tax_enabled' => false, 'tax_rate' => 0.0,
                'terms_days' => 30, 'notes' => '', 'client' => ['name' => 'Acme', 'address' => '', 'email' => ''],
            ],
            'ai_ready' => false, 'ai_profiles' => [], 'ai_default_profile' => '',
            'unbilled' => $this->silent(),
        ]);

        $this->assertStringContainsString('timeinvoice-totals', $html, 'live totals region rendered');
        $this->assertStringContainsString('data-recalc-fields', $html);
        $this->assertStringContainsString('terms_days', $html, 'per-invoice payment terms field rendered');
        $this->assertStringContainsString('Inherited from the project', $html);
        $this->assertStringNotContainsString('timeinvoice-generate-note', $html, 'AI block hidden when ai_ready is false');
    }

    public function testListRendersPickerWhenCrossProject(): void
    {
        $html = $this->render('TimeInvoice:invoice/list', [
            'project' => null,
            'projects' => [7 => 'Acme Retainer', 9 => 'Beta Build'],
            'invoices' => [[
                'id' => 'a', 'project_id' => 7, 'number' => 'INV-2026-001',
                'status' => 'sent', 'issue_date' => '2026-09-01', 'total' => 660.0,
                'currency' => ['symbol' => '$'],
            ]],
            'outstanding' => 660.0,
            'missing_dependency' => false,
            'currency' => ['code' => 'USD', 'symbol' => '$'],
        ]);

        $this->assertStringContainsString('Acme Retainer', $html, 'project picker option rendered');
        $this->assertStringContainsString('New invoice', $html);
        $this->assertStringContainsString('INV-2026-001', $html);
        $this->assertStringContainsString('Issued', $html);
    }

    public function testProjectSettingsRendersWithNoStoredDefaults(): void
    {
        $html = $this->render('TimeInvoice:invoice/project_settings', [
            'project' => ['id' => 1, 'name' => 'Acme Retainer'],
            'values'  => [],
        ]);

        $this->assertStringContainsString('Acme Retainer', $html);
        $this->assertStringContainsString('client_email', $html);
        $this->assertStringContainsString('terms_days', $html);
        $this->assertStringContainsString('USD', $html, 'currency falls back to USD when unset');
    }

    public function testConfigSidebarRenders(): void
    {
        $this->assertStringContainsString('Invoices', $this->render('TimeInvoice:config/sidebar', []));
    }

    public function testProjectSidebarRendersBothEntries(): void
    {
        $html = $this->render('TimeInvoice:invoice/sidebar', ['project' => ['id' => 1, 'name' => 'P']]);
        $this->assertStringContainsString('Invoices', $html);
        $this->assertStringContainsString('Invoice settings', $html);
    }

    /**
     * The live-totals handler recalculates only when a changed input's name
     * appears in data-recalc-fields. If the form renders a field under a
     * different name, the strip silently never updates — a failure no unit test
     * of the endpoint can see. Pins the contract between template and JS.
     */
    public function testEveryRecalcFieldNameExistsInTheRenderedForm(): void
    {
        $html = $this->render('TimeInvoice:invoice/form', [
            'project'  => ['id' => 1, 'name' => 'P'],
            'values'   => [
                'project_id' => 1, 'id' => '', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
                'granularity' => 'task', 'rate' => 150.0, 'tax_enabled' => false, 'tax_rate' => 0.0,
                'terms_days' => 30, 'notes' => '', 'client' => ['name' => '', 'address' => '', 'email' => ''],
            ],
            'ai_ready' => false, 'ai_profiles' => [], 'ai_default_profile' => '',
            'unbilled' => $this->silent(),
        ]);

        $this->assertMatchesRegularExpression('/data-recalc-fields="([^"]+)"/', $html);
        preg_match('/data-recalc-fields="([^"]+)"/', $html, $m);
        $fields = explode(',', $m[1]);
        $this->assertNotEmpty($fields);

        preg_match_all('/name="([^"\[]+)/', $html, $names);
        $rendered = array_unique($names[1]);

        foreach ($fields as $field) {
            $this->assertContains($field, $rendered, "data-recalc-fields lists '$field' but the form renders no input with that name — live totals would never fire for it");
        }
    }

    /** The AJAX posts $form.serialize(), so project_id must be in the form. */
    public function testFormCarriesProjectIdAndCsrfForTheTotalsPost(): void
    {
        $html = $this->render('TimeInvoice:invoice/form', [
            'project'  => ['id' => 7, 'name' => 'P'],
            'values'   => [
                'project_id' => 7, 'id' => '', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
                'granularity' => 'task', 'rate' => 150.0, 'tax_enabled' => false, 'tax_rate' => 0.0,
                'terms_days' => 30, 'notes' => '', 'client' => ['name' => '', 'address' => '', 'email' => ''],
            ],
            'ai_ready' => false, 'ai_profiles' => [], 'ai_default_profile' => '',
            'unbilled' => $this->silent(),
        ]);

        $this->assertStringContainsString('name="project_id"', $html, 'previewTotals() reads project_id from the POST body');
        $this->assertStringContainsString('name="csrf_token"', $html, 'previewTotals() calls checkCSRFForm()');
    }

    /** View opens in a new tab so it cannot navigate the app away; Download must not. */
    public function testViewPdfOpensInANewTabAndDownloadDoesNot(): void
    {
        $html = $this->render('TimeInvoice:invoice/show', [
            'project'    => ['id' => 1, 'name' => 'P'],
            'invoice'    => $this->snapshot(),
            'status'     => 'draft',
            'invoice_id' => 'abc',
            'unbilled'   => $this->silent(),
        ]);

        preg_match('/<a[^>]*>\s*View PDF\s*<\/a>/', $html, $view);
        preg_match('/<a[^>]*>\s*Download PDF\s*<\/a>/', $html, $down);
        $this->assertNotEmpty($view, 'View PDF link rendered');
        $this->assertNotEmpty($down, 'Download PDF link rendered');

        $this->assertStringContainsString('target="_blank"', $view[0], 'View PDF must open in a new tab');
        $this->assertStringContainsString('inline=1', html_entity_decode($view[0]), 'View PDF must request inline rendering');

        $this->assertStringNotContainsString('target="_blank"', $down[0], 'Download PDF stays in the tab');
        $this->assertStringNotContainsString('inline=1', html_entity_decode($down[0]), 'Download PDF must not request inline');
    }
}
