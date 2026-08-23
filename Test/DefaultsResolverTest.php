<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Model\DefaultsResolver;

class DefaultsResolverTest extends Base
{
    public function testProjectOverridesGlobalAndFormOverridesProject(): void
    {
        $global  = ['rate' => 100.0, 'tax_rate' => 8.0, 'terms' => 'Net 30', 'currency' => ['code' => 'USD', 'symbol' => '$']];
        $project = ['rate' => 150.0, 'client' => ['name' => 'Acme']];
        $form    = ['rate' => 175.0];
        $r = DefaultsResolver::resolve($global, $project, $form);
        $this->assertSame(175.0, $r['rate']);              // form wins
        $this->assertSame('Acme', $r['client']['name']);   // from project
        $this->assertSame(8.0, $r['tax_rate']);            // from global
        $this->assertSame('USD', $r['currency']['code']);
    }

    public function testEmptyFormValuesDoNotClobber(): void
    {
        $r = DefaultsResolver::resolve(['rate' => 100.0], ['rate' => 150.0], ['rate' => '']);
        $this->assertSame(150.0, $r['rate']); // empty string in form ignored
    }
}
