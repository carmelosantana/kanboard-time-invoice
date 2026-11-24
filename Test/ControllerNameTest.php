<?php

namespace Kanboard\Plugin\TimeInvoice\Test;

use PHPUnit\Framework\TestCase;

/**
 * Kanboard's AccessMap keys rules by the short controller name alone, so a plugin's settings
 * controller carries the plugin's name: another plugin's rule for a shared `SettingsController`
 * would govern this one too (#7337).
 */
class ControllerNameTest extends TestCase
{
    public function testSettingsControllerHasAPluginScopedName(): void
    {
        $this->assertTrue(class_exists('Kanboard\Plugin\TimeInvoice\Controller\TimeInvoiceSettingsController'));
        $this->assertFalse(class_exists('Kanboard\Plugin\TimeInvoice\Controller\SettingsController'));
    }
}
