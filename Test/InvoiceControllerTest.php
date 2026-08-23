<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Controller\InvoiceController;

class InvoiceControllerTest extends Base
{
    public function testDependencyGateFalseWithoutTimeReport(): void
    {
        $c = new InvoiceController($this->container);
        $ref = new ReflectionMethod($c, 'hasTimeReport');
        $ref->setAccessible(true);
        $this->assertFalse($ref->invoke($c));
        $this->container['timeReportModel'] = fn ($x) => new stdClass();
        $this->assertTrue($ref->invoke($c));
    }

    public function testGlobalDefaultsDecodeFromConfig(): void
    {
        $this->container['configModel']->save(['timeinvoice_business' => json_encode(['name' => 'Me'])]);
        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'globalDefaults');
        $m->setAccessible(true);
        $defaults = $m->invoke($c);
        $this->assertSame('Me', $defaults['business']['name']);
    }
}
