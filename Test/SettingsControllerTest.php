<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Controller\SettingsController;

class SettingsControllerTest extends Base
{
    public function testCurrentSettingsReadsConfig(): void
    {
        $this->container['configModel']->save([
            'timeinvoice_rate' => '175',
            'timeinvoice_business' => json_encode(['name' => 'CS Consulting']),
        ]);
        $c = new SettingsController($this->container);
        $m = new ReflectionMethod($c, 'currentSettings');
        $m->setAccessible(true);
        $s = $m->invoke($c);
        $this->assertSame(175.0, $s['rate']);
        $this->assertSame('CS Consulting', $s['business']['name']);
        $this->assertSame('INV-{YYYY}-{seq}', $s['number_format']); // default
    }
}
