<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Controller\ProjectSettingsController;
use Kanboard\Core\Security\Role;

class ProjectSettingsControllerTest extends Base
{
    public function testCurrentDefaultsEmptyForFreshProject(): void
    {
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);
        $c = new ProjectSettingsController($this->container);
        $m = new ReflectionMethod($c, 'currentDefaults');
        $m->setAccessible(true);
        $this->assertSame([], $m->invoke($c, $pid));
    }

    public function testSaveWritesDefaultsReadableByInvoiceController(): void
    {
        $this->loginAsAdmin();
        $this->silenceRedirects();
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'Acme Retainer']);

        $c = new ProjectSettingsController($this->container);
        $this->container['request'] = new \Kanboard\Core\Http\Request($this->container, [], [], [
            'csrf_token'      => $this->container['token']->getCSRFToken(),
            'project_id'      => (string) $pid,
            'rate'            => '165.50',
            'currency_code'   => 'GBP',
            'currency_symbol' => '£',
            'terms_days'      => '15',
            'terms'           => 'Net 15. Late fees apply.',
            'client_name'     => 'Acme Inc',
            'client_address'  => "1 Road\nTown",
            'client_email'    => 'ap@acme.example',
        ]);
        $c->save();

        // Read back through the consumer that actually uses this key.
        $ic = new \Kanboard\Plugin\TimeInvoice\Controller\InvoiceController($this->container);
        $pd = new ReflectionMethod($ic, 'projectDefaults');
        $pd->setAccessible(true);
        $d = $pd->invoke($ic, $pid);

        $this->assertSame(165.5, $d['rate']);
        $this->assertSame('GBP', $d['currency']['code']);
        $this->assertSame('£', $d['currency']['symbol']);
        $this->assertSame(15, $d['terms_days']);
        $this->assertSame('Net 15. Late fees apply.', $d['terms']);
        $this->assertSame('Acme Inc', $d['client']['name']);
        $this->assertSame('ap@acme.example', $d['client']['email']);
    }

    public function testSaveIsBlockedForNonManager(): void
    {
        $_SESSION['user'] = ['id' => 2, 'role' => Role::APP_USER];
        $this->silenceRedirects();
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);

        $c = new ProjectSettingsController($this->container);
        $this->container['request'] = new \Kanboard\Core\Http\Request($this->container, [], [], [
            'csrf_token' => $this->container['token']->getCSRFToken(),
            'project_id' => (string) $pid,
            'rate'       => '999',
        ]);

        try {
            $c->save();
            $this->fail('a non-manager must not be able to write project invoice defaults');
        } catch (\Kanboard\Core\Controller\AccessForbiddenException $e) {
            // expected
        }

        $this->assertSame('{}', $this->container['projectMetadataModel']->get($pid, 'timeinvoice:defaults', '{}'), 'non-manager write must be rejected');
    }

    public function testCanManageAllowsAdminAndProjectManagerButNotAMember(): void
    {
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);
        // addUser() returns false for a user id that does not exist, which would
        // silently leave the project memberless — create real users first.
        $um = new \Kanboard\Model\UserModel($this->container);
        $mgr    = $um->create(['username' => 'mgr', 'name' => 'Manager']);
        $member = $um->create(['username' => 'member', 'name' => 'Member']);
        $this->assertTrue($this->container['projectUserRoleModel']->addUser($pid, $mgr, Role::PROJECT_MANAGER));
        $this->assertTrue($this->container['projectUserRoleModel']->addUser($pid, $member, Role::PROJECT_MEMBER));

        $this->loginAsAdmin();
        $c = new ProjectSettingsController($this->container);
        $m = new ReflectionMethod($c, 'canManage');
        $m->setAccessible(true);
        $this->assertTrue($m->invoke($c, $pid, 1), 'app admin');

        $_SESSION['user'] = ['id' => $mgr, 'role' => Role::APP_USER];
        $c2 = new ProjectSettingsController($this->container);
        $m2 = new ReflectionMethod($c2, 'canManage');
        $m2->setAccessible(true);
        $this->assertTrue($m2->invoke($c2, $pid, $mgr), 'project manager');
        $this->assertFalse($m2->invoke($c2, $pid, $member), 'plain project member must not manage billing');
    }

    public function testBuildDefaultsCoercesTypesAndFillsBlanks(): void
    {
        $c = new ProjectSettingsController($this->container);
        $m = new ReflectionMethod($c, 'buildDefaults');
        $m->setAccessible(true);

        $d = $m->invoke($c, []);
        $this->assertSame(0.0, $d['rate']);
        $this->assertSame('USD', $d['currency']['code']);
        $this->assertSame('$', $d['currency']['symbol']);
        $this->assertSame(30, $d['terms_days']);
        $this->assertSame('', $d['client']['name']);

        $d2 = $m->invoke($c, ['rate' => '99.9', 'terms_days' => '7']);
        $this->assertSame(99.9, $d2['rate']);
        $this->assertSame(7, $d2['terms_days']);
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
