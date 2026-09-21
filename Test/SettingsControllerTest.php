<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Controller\SettingsController;
use Kanboard\Core\Security\Role;

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

    public function testShowThrowsForNonAdmin(): void
    {
        $_SESSION['user'] = ['id' => 2, 'role' => Role::APP_USER];
        $c = new SettingsController($this->container);
        $this->expectException(\Kanboard\Core\Controller\AccessForbiddenException::class);
        $c->show();
    }

    /**
     * Full write-path round-trip through the guarded save() action, run as an
     * admin. Exercises the real controller: the admin gate must pass, the CSRF
     * form check must succeed against a minted token, and configModel must
     * persist every field so currentSettings() reads the written values back.
     */
    public function testSaveWritesGlobalConfigAsAdmin(): void
    {
        $this->loginAsAdmin();
        $this->silenceRedirects();

        $c = new SettingsController($this->container);
        $this->assertTrue($c->userSession->isAdmin(), 'harness session must be admin for the write-path test');

        $csrf = $this->container['token']->getCSRFToken();
        $this->container['request'] = new \Kanboard\Core\Http\Request($this->container, [], [], [
            'csrf_token'      => $csrf,
            'business_name'   => 'CS Consulting LLC',
            'business_email'  => 'me@carmelosantana.com',
            'currency_code'   => 'GBP',
            'currency_symbol' => '£',
            'rate'            => '185.5',
            'tax_enabled'     => '1',
            'tax_rate'        => '20',
            'terms_days'      => '15',
            'number_format'   => 'CS-{YYYY}-{seq}',
        ]);

        $c->save();

        $m = new ReflectionMethod($c, 'currentSettings');
        $m->setAccessible(true);
        $s = $m->invoke($c);

        $this->assertSame('CS Consulting LLC', $s['business']['name']);
        $this->assertSame('GBP', $s['currency']['code']);
        $this->assertSame('£', $s['currency']['symbol']);
        $this->assertSame(185.5, $s['rate']);
        $this->assertTrue($s['tax_enabled'], 'tax_enabled must coerce to a bool true');
        $this->assertSame(15, $s['terms_days']);
        $this->assertSame('CS-{YYYY}-{seq}', $s['number_format']);
    }

    /**
     * The admin gate must block a non-admin: save() returns before writing any
     * config, so the settings remain at their defaults.
     */
    public function testSaveIsBlockedForNonAdmin(): void
    {
        $_SESSION['user'] = ['id' => 2, 'role' => Role::APP_MANAGER];
        $this->silenceRedirects();

        $c = new SettingsController($this->container);
        $this->assertFalse($c->userSession->isAdmin(), 'sanity: the session is not admin');

        $this->container['request'] = new \Kanboard\Core\Http\Request($this->container, [], [], [
            'csrf_token'    => $this->container['token']->getCSRFToken(),
            'business_name' => 'Should Not Persist',
            'rate'          => '999',
        ]);

        try {
            $c->save();
            $this->fail('non-admin save must throw AccessForbiddenException');
        } catch (\Kanboard\Core\Controller\AccessForbiddenException $e) {
            // expected
        }

        $this->assertSame('', $this->container['configModel']->get('timeinvoice_business', ''), 'non-admin write must be rejected');
        $this->assertSame('', $this->container['configModel']->get('timeinvoice_rate', ''), 'non-admin write must be rejected');
    }

    public function testCurrentSettingsReadsAiStyle(): void
    {
        $this->container['configModel']->save(['timeinvoice_ai_style' => 'Terse and formal.']);
        $c = new SettingsController($this->container);
        $m = new ReflectionMethod($c, 'currentSettings');
        $m->setAccessible(true);
        $this->assertSame('Terse and formal.', $m->invoke($c)['ai_style']);
    }

    public function testCurrentSettingsAiStyleDefaultsEmpty(): void
    {
        $c = new SettingsController($this->container);
        $m = new ReflectionMethod($c, 'currentSettings');
        $m->setAccessible(true);
        $this->assertSame('', $m->invoke($c)['ai_style']);
    }

    public function testSaveWritesAiStyleAsAdmin(): void
    {
        $_SESSION['user'] = ['id' => 1, 'role' => \Kanboard\Core\Security\Role::APP_ADMIN];
        $this->silenceRedirects();
        $c = new SettingsController($this->container);
        $this->container['request'] = new \Kanboard\Core\Http\Request($this->container, [], [], [
            'csrf_token' => $this->container['token']->getCSRFToken(),
            'ai_style'   => 'First-person plural, outcome-focused.',
        ]);
        $c->save();
        $this->assertSame('First-person plural, outcome-focused.', $this->container['configModel']->get('timeinvoice_ai_style', ''));
    }

    /** Seat an app-admin user in the session (harness starts with an empty session). */
    private function loginAsAdmin(): void
    {
        $_SESSION['user'] = ['id' => 1, 'role' => Role::APP_ADMIN];
    }

    /** Swap in a Response whose send() is inert so redirect() cannot emit headers under PHPUnit. */
    private function silenceRedirects(): void
    {
        $this->container['response'] = function ($c) {
            return new class($c) extends \Kanboard\Core\Http\Response {
                public function send() {}
            };
        };
    }
}
