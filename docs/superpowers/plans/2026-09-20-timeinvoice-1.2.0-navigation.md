# TimeInvoice 1.2.0 — Navigation & Visibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every invoice control the user asked for reachable — create, set rates, set billing terms, preview, and see an invoice — without changing the frozen snapshot shape.

**Architecture:** Pure controller + template work on an existing plugin. New surfaces are a Project → Invoice settings page (writing the already-read-but-never-written `timeinvoice:defaults` project-metadata key), an invoice show page (reusing the existing `snapshotForPdf()` so drafts show live figures and sent/paid show frozen ones), and two JSON endpoints that reuse `freezeSnapshot()` for live totals and TimeReport's `participants()` for an under-billing warning. No DB schema change, no new dependency, no change to how a `sent` or `paid` record is stored.

**Tech Stack:** PHP 8.4, Kanboard 1.2.47 plugin API, PHPUnit 9.6, buildless plain JS + jQuery (global `KB`), vendored FPDF. No build step.

**Spec:** `docs/superpowers/specs/2026-09-20-timeinvoice-ui-completion.md`

## Global Constraints

- **PHP >= 8.4**, **Kanboard >= 1.2.47**. `Plugin::isPhpCompatible()` gate stays at `80400`.
- **No DB migration.** Global config → `configModel`; project config → `projectMetadataModel`; user config → `userMetadataModel`.
- **No inline JS, no `<script>` in templates, no `on*=` attributes** — CSP-safe delegated handlers only, in `Assets/js/timeinvoice.js`. Enforced by `Test/TemplateAssetsTest::testNoInlineHandlersInTemplates`.
- **No unescaped `%` in single-argument `t('...')` calls** — use `%%`. Enforced by `Test/TemplateAssetsTest::testNoUnescapedPercentInSingleArgTranslations`.
- **The frozen snapshot shape does not change in this release.** No new key is written to a `sent` or `paid` record.
- **TimeReport's `report()` is called with exactly 6 positional arguments.** That arity is pinned by a test on TimeReport's side (`TimeReport/Test/TimeReportModelTest.php:769`). Do not add arguments in this release.
- **Version must match in two places**: `plugin.json` `"version"` and `Plugin::getPluginVersion()`. CI fails the release if the git tag disagrees with `plugin.json`.
- **Templates are referenced as `TimeInvoice:folder/file`** → `Template/folder/file.php`. Assets as `plugins/TimeInvoice/Assets/...`.
- **Every new controller action that reads POST must call `$this->checkCSRFForm()`**; every GET action that mutates must call `$this->checkCSRFParam()`.
- **`$this->request->getValues()` is single-use/stateful.** Call it once, store the array, pass it around. A second call returns `[]`. (This caused a live 400 regression — see commit `e0b010b`.)

### Test commands

Full suite (also regenerates `tests/plugin-bootstrap.php`; run this once before any filtered run):

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeInvoice
```

Single test (fast, ~0.3s):

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter TEST_NAME
```

**Baseline before Task 1: 59 tests, 221 assertions, OK.**

> **`tests/units.sqlite.xml` sets `stopOnError="true" stopOnFailure="true"`.** The run
> halts at the first red test, so a filtered run of several new tests will report
> `Tests: 1` while any of them is still failing. That is the harness stopping early,
> not the filter failing to match. Expect the full count only once they are green.

### Test file conventions

Every test file starts:

```php
<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
```

Protected methods are exercised via `ReflectionMethod` + `setAccessible(true)`. Two helpers are repeated per class as needed:

```php
/** Seat an app-admin user in the session (harness starts with an empty session). */
private function loginAsAdmin(): void
{
    $_SESSION['user'] = ['id' => 1, 'role' => \Kanboard\Core\Security\Role::APP_ADMIN];
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
```

---

## File Structure

| File | Responsibility | Change |
|------|----------------|--------|
| `Plugin.php` | Route table, hooks, metadata | Modify — 5 new routes, 1 new hook, version bump |
| `Controller/SettingsController.php` | Global admin settings | Modify — `layout->config`, `AccessForbiddenException` |
| `Controller/ProjectSettingsController.php` | Per-project invoice defaults + client | **Create** |
| `Controller/InvoiceController.php` | Invoice list/form/show/pdf/lifecycle + JSON endpoints | Modify — `accessibleProjects()`, `show()`, inline PDF, `previewTotals()`, `unbilledParticipants()`, terms override |
| `Helper/InvoiceHelper.php` | Money + status presentation | Modify — `statusLabel('sent')` → "Issued" |
| `Template/config/sidebar.php` | Admin nav entry | **Create** |
| `Template/invoice/project_settings.php` | Per-project settings form | **Create** |
| `Template/invoice/show.php` | Invoice detail page + all actions | **Create** |
| `Template/invoice/_banner.php` | Under-billing warning partial | **Create** |
| `Template/invoice/sidebar.php` | Project sidebar entries | Modify — add settings link |
| `Template/invoice/list.php` | Invoice list | Modify — collapse row, add picker + create |
| `Template/invoice/form.php` | Draft form | Modify — terms_days, live totals region, banner |
| `Assets/js/timeinvoice.js` | Delegated handlers | Modify — live totals, Issue confirm text |
| `Assets/css/timeinvoice.css` | Plugin styles | Modify — show page, totals, banner |
| `README.md` | User-facing docs | Modify — document new controls |
| `Test/*.php` | PHPUnit coverage | Modify/create per task |

---

### Task 1: Settings page discoverability

Fixes audit findings A2 and A3. TimeInvoice is the only plugin in the suite with an admin settings page that is unreachable from the UI.

**Files:**
- Create: `Template/config/sidebar.php`
- Modify: `Plugin.php:27-28` (add hook), `Controller/SettingsController.php:25-58`
- Test: `Test/SettingsControllerTest.php`, `Test/TemplateAssetsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: the `timeinvoice/settings` page is reachable from Settings; `SettingsController::show()` and `::save()` throw `Kanboard\Core\Controller\AccessForbiddenException` for non-admins instead of redirecting.

- [x] **Step 1: Write the failing tests**

Add to `Test/TemplateAssetsTest.php`:

```php
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
```

Add to `Test/SettingsControllerTest.php`:

```php
public function testShowThrowsForNonAdmin(): void
{
    $_SESSION['user'] = ['id' => 2, 'role' => Role::APP_USER];
    $c = new SettingsController($this->container);
    $this->expectException(\Kanboard\Core\Controller\AccessForbiddenException::class);
    $c->show();
}
```

And replace the body of the existing `testSaveIsBlockedForNonAdmin()` with the throwing contract:

```php
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
```

- [x] **Step 2: Run tests to verify they fail**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter 'testConfigSidebarPartialExistsAndLinks|testPluginRegistersConfigSidebarHook|testShowThrowsForNonAdmin|testSaveIsBlockedForNonAdmin'
```

Expected: FAIL — missing file `Template/config/sidebar.php`, missing hook string, and `show()`/`save()` redirect instead of throwing.

- [x] **Step 3: Create the sidebar partial**

Create `Template/config/sidebar.php` (bare `<li>` — it is injected into core's `<ul>`):

```php
<li>
    <?= $this->url->link(t('Invoices'), 'SettingsController', 'show', array('plugin' => 'TimeInvoice')) ?>
</li>
```

- [x] **Step 4: Register the hook**

In `Plugin.php`, in the "Entry points" block after the existing `template:header:dropdown` attach, add:

```php
$this->hook->on('template:config:sidebar', ['template' => 'TimeInvoice:config/sidebar']);
```

- [x] **Step 5: Switch the controller to the config layout and the throwing gate**

In `Controller/SettingsController.php`, add the import under the existing `use`:

```php
use Kanboard\Core\Controller\AccessForbiddenException;
```

Replace `show()` and the gate in `save()`:

```php
public function show(): void
{
    if (! $this->userSession->isAdmin()) {
        throw new AccessForbiddenException();
    }
    $this->response->html($this->helper->layout->config('TimeInvoice:invoice/settings', [
        'title'  => t('Settings') . ' &gt; ' . t('Invoices'),
        'values' => $this->currentSettings(),
    ]));
}

public function save(): void
{
    if (! $this->userSession->isAdmin()) {
        throw new AccessForbiddenException();
    }
    $this->checkCSRFForm();
    // ... existing configModel->save(...) body unchanged ...
}
```

Note: `layout->config()` replaces `$params['values']` with `configModel->getAll()` only when `values` is empty — this page always passes a non-empty `values`, so the settings form is unaffected.

- [x] **Step 6: Run tests to verify they pass**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter 'testConfigSidebarPartialExistsAndLinks|testPluginRegistersConfigSidebarHook|testShowThrowsForNonAdmin|testSaveIsBlockedForNonAdmin'
```

Expected: PASS (4 tests).

- [x] **Step 7: Run the full suite**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeInvoice
```

Expected: OK, 62 tests.

- [x] **Step 8: Commit**

```bash
git add Template/config/sidebar.php Plugin.php Controller/SettingsController.php Test/SettingsControllerTest.php Test/TemplateAssetsTest.php
git commit -m "fix: link invoice settings from the admin sidebar and use the config layout

The settings page was routed but reachable only by typing the URL. Attaches
template:config:sidebar (the suite's house pattern, cf. AiConnector), renders
with layout->config so the config sidebar stays visible, and replaces the
silent non-admin redirect with AccessForbiddenException."
```

---

### Task 2: Project picker and create button on the global list

Fixes audit finding A1 — the top-level Invoices page has no way to start an invoice.

**Files:**
- Modify: `Controller/InvoiceController.php:17-21` (add `accessibleProjects()`), `:50-70` (`list()`), `:72-90` (`project()`)
- Modify: `Template/invoice/list.php:1-13`
- Test: `Test/InvoiceControllerTest.php`

**Interfaces:**
- Consumes: `accessibleProjectIds(int $userId): array` (existing).
- Produces: `protected function accessibleProjects(int $userId): array` returning `array<int,string>` of `project_id => name`, sorted by name — consumed by the list template's picker.

- [x] **Step 1: Write the failing test**

Add to `Test/InvoiceControllerTest.php`:

```php
public function testAccessibleProjectsReturnsIdNameMapForGuardedIdsOnly(): void
{
    $this->loginAsAdmin();
    $pm = new \Kanboard\Model\ProjectModel($this->container);
    $a = $pm->create(['name' => 'Zulu Project']);
    $b = $pm->create(['name' => 'Alpha Project']);

    $c = new InvoiceController($this->container);
    $m = new ReflectionMethod($c, 'accessibleProjects');
    $m->setAccessible(true);
    $projects = $m->invoke($c, 1);

    $ids = new ReflectionMethod($c, 'accessibleProjectIds');
    $ids->setAccessible(true);
    $guarded = $ids->invoke($c, 1);

    $this->assertSame($guarded, array_keys($projects), 'picker options must be exactly the ids the access guard allows, in the same set');
    $this->assertSame('Alpha Project', reset($projects), 'sorted by name, not id');
    $this->assertArrayHasKey($a, $projects);
    $this->assertArrayHasKey($b, $projects);
}

public function testAccessibleProjectsEmptyWhenNoProjects(): void
{
    $c = new InvoiceController($this->container);
    $m = new ReflectionMethod($c, 'accessibleProjects');
    $m->setAccessible(true);
    $this->assertSame([], $m->invoke($c, 99));
}

/** Seat an app-admin user in the session (harness starts with an empty session). */
private function loginAsAdmin(): void
{
    $_SESSION['user'] = ['id' => 1, 'role' => \Kanboard\Core\Security\Role::APP_ADMIN];
}
```

Note: sort both sides before comparing, and assert the fixture is non-empty first. `accessibleProjectIds()` resolves through `getActiveProjectsByUser()`, which is **membership-based — being an app admin is not enough**. Without `projectUserRoleModel->addUser($pid, 1, Role::PROJECT_MANAGER)` on each project, both sides come back `[]` and the set-equality assertion passes vacuously.

- [x] **Step 2: Run test to verify it fails**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter 'testAccessibleProjects'
```

Expected: FAIL with `ReflectionException: Method accessibleProjects does not exist`.

- [x] **Step 3: Implement `accessibleProjects()`**

In `Controller/InvoiceController.php`, directly after `accessibleProjectIds()`:

```php
/**
 * The picker's options, derived from the SAME id list the access guard uses,
 * so the dropdown can never offer a project that form()/project() will reject.
 * One hashtable query rather than N getById() calls.
 *
 * @return array<int,string> project_id => name, sorted by name
 */
protected function accessibleProjects(int $userId): array
{
    $ids = $this->accessibleProjectIds($userId);
    if ($ids === []) {
        return [];
    }
    $rows = $this->db->hashtable(\Kanboard\Model\ProjectModel::TABLE)
        ->in('id', $ids)
        ->getAll('id', 'name');
    asort($rows);
    return $rows;
}
```

- [x] **Step 4: Run test to verify it passes**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter 'testAccessibleProjects'
```

Expected: PASS (2 tests).

- [x] **Step 5: Pass the projects into both list views**

In `list()`, add to the non-dependency-missing `html()` params array:

```php
'projects' => $this->accessibleProjects($userId),
```

In the `missing_dependency` early-return params array add `'projects' => [],` and in `project()` add `'projects' => [],` (the per-project list already has its project and shows the direct create button).

- [x] **Step 6: Add the picker to the template**

In `Template/invoice/list.php`, replace the `<?php if ($project): ?>` block (lines 6-8) with:

```php
    <?php if ($project): ?>
        <p><?= $this->url->link(t('New invoice'), 'InvoiceController', 'form', array('plugin' => 'TimeInvoice', 'project_id' => $project['id']), false, 'btn btn-blue') ?></p>
    <?php elseif (! empty($projects)): ?>
        <form method="get" action="<?= $this->url->href('InvoiceController', 'form', array('plugin' => 'TimeInvoice')) ?>" class="timeinvoice-new">
            <input type="hidden" name="plugin" value="TimeInvoice">
            <input type="hidden" name="controller" value="InvoiceController">
            <input type="hidden" name="action" value="form">
            <label for="timeinvoice-new-project"><?= t('Project') ?></label>
            <select name="project_id" id="timeinvoice-new-project">
                <?php foreach ($projects as $pid => $pname): ?>
                    <option value="<?= (int) $pid ?>"><?= $this->text->e($pname) ?></option>
                <?php endforeach ?>
            </select>
            <button type="submit" class="btn btn-blue"><?= t('New invoice') ?></button>
        </form>
    <?php endif ?>
```

The hidden `plugin`/`controller`/`action` fields are required because Kanboard resolves plugin routes from query parameters when a GET form posts its own query string.

- [x] **Step 7: Style the picker**

Append to `Assets/css/timeinvoice.css`:

```css
.timeinvoice-new { margin: 0 0 12px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
```

- [x] **Step 8: Run the full suite**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeInvoice
```

Expected: OK, 64 tests.

- [x] **Step 9: Commit**

```bash
git add Controller/InvoiceController.php Template/invoice/list.php Assets/css/timeinvoice.css Test/InvoiceControllerTest.php
git commit -m "feat: project picker and New invoice button on the global list

The cross-project list passed project=null, which gated off the only create
button, leaving the top-level Invoices page a dead end. Adds accessibleProjects()
derived from the same ids as the access guard, so the picker cannot offer a
project the form will reject."
```

---

### Task 3: Project → Invoice settings page

Fixes audit finding A4 — `timeinvoice:defaults` is read by `projectDefaults()` and honoured by `freezeSnapshot()`, but nothing writes it.

**Files:**
- Create: `Controller/ProjectSettingsController.php`, `Template/invoice/project_settings.php`
- Modify: `Plugin.php` (2 routes), `Template/invoice/sidebar.php`
- Test: `Test/ProjectSettingsControllerTest.php` (create)

**Interfaces:**
- Consumes: `projectMetadataModel` (core), `Kanboard\Core\Security\Role`.
- Produces: the `timeinvoice:defaults` project-metadata key, a JSON object with keys `rate` (float), `currency` (`{code,symbol}`), `terms_days` (int), `terms` (string), `client` (`{name,address,email}`). This is the exact shape `InvoiceController::projectDefaults()` already decodes and `DefaultsResolver::resolve()` already merges.

- [x] **Step 1: Write the failing test**

Create `Test/ProjectSettingsControllerTest.php`:

```php
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
            'client_address'  => '1 Road\nTown',
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
        } catch (\Kanboard\Core\Controller\AccessForbiddenException $e) {
            // acceptable: gate may throw
        }

        $this->assertSame('{}', $this->container['projectMetadataModel']->get($pid, 'timeinvoice:defaults', '{}'), 'non-manager write must be rejected');
    }

    public function testCanManageAllowsAdmin(): void
    {
        $this->loginAsAdmin();
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);
        $c = new ProjectSettingsController($this->container);
        $m = new ReflectionMethod($c, 'canManage');
        $m->setAccessible(true);
        $this->assertTrue($m->invoke($c, $pid, 1));
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
```

- [x] **Step 2: Run test to verify it fails**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ProjectSettingsControllerTest.php --no-coverage
```

Expected: FAIL — `Class ProjectSettingsController not found`.

- [x] **Step 3: Write the controller**

Create `Controller/ProjectSettingsController.php`:

```php
<?php

namespace Kanboard\Plugin\TimeInvoice\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Security\Role;

/**
 * Per-project invoice defaults: rate, currency, payment terms, default notes
 * and the client block. Persists the `timeinvoice:defaults` project-metadata
 * key that InvoiceController::projectDefaults() already reads and
 * DefaultsResolver already layers under the form's own values.
 *
 * Authorization follows the suite convention (cf. SchedulerPlugin): app admin
 * OR project manager on this specific project.
 */
class ProjectSettingsController extends BaseController
{
    private const KEY = 'timeinvoice:defaults';

    protected function canManage(int $projectId, int $userId): bool
    {
        if ($this->userSession->isAdmin()) {
            return true;
        }
        return $this->projectUserRoleModel->getUserRole($projectId, $userId) === Role::PROJECT_MANAGER;
    }

    /** @return array the decoded defaults blob, or [] when unset */
    protected function currentDefaults(int $projectId): array
    {
        $raw = $this->projectMetadataModel->get($projectId, self::KEY, '{}');
        return json_decode($raw ?: '{}', true) ?: [];
    }

    /** Map request values → the stored blob. Pure enough to unit-test. */
    protected function buildDefaults(array $v): array
    {
        return [
            'rate'       => (float) ($v['rate'] ?? 0),
            'currency'   => ['code' => (string) ($v['currency_code'] ?? 'USD'), 'symbol' => (string) ($v['currency_symbol'] ?? '$')],
            'terms_days' => (int) ($v['terms_days'] ?? 30),
            'terms'      => (string) ($v['terms'] ?? ''),
            'client'     => [
                'name'    => (string) ($v['client_name'] ?? ''),
                'address' => (string) ($v['client_address'] ?? ''),
                'email'   => (string) ($v['client_email'] ?? ''),
            ],
        ];
    }

    public function show(): void
    {
        $userId = $this->userSession->getId();
        $projectId = $this->request->getIntegerParam('project_id');
        if (! $this->canManage($projectId, $userId)) {
            throw new AccessForbiddenException();
        }
        $this->response->html($this->helper->layout->app('TimeInvoice:invoice/project_settings', [
            'title'   => t('Invoice settings'),
            'project' => $this->projectModel->getById($projectId),
            'values'  => $this->currentDefaults($projectId),
        ]));
    }

    public function save(): void
    {
        $this->checkCSRFForm();
        $userId = $this->userSession->getId();
        $values = $this->request->getValues();
        $projectId = (int) ($values['project_id'] ?? 0);

        if (! $this->canManage($projectId, $userId)) {
            throw new AccessForbiddenException();
        }

        $this->projectMetadataModel->save($projectId, [
            self::KEY => json_encode($this->buildDefaults($values), JSON_PRESERVE_ZERO_FRACTION),
        ]);
        $this->flash->success(t('Invoice settings saved.'));
        $this->response->redirect($this->helper->url->to('ProjectSettingsController', 'show', ['plugin' => 'TimeInvoice', 'project_id' => $projectId]));
    }
}
```

- [x] **Step 4: Write the template**

Create `Template/invoice/project_settings.php`:

```php
<div class="page-header"><h2><?= t('Invoice settings for %s', $project['name']) ?></h2></div>

<form method="post" action="<?= $this->url->href('ProjectSettingsController', 'save', array('plugin' => 'TimeInvoice')) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>
    <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">

    <h3><?= t('Billing') ?></h3>
    <?= $this->form->label(t('Hourly rate'), 'rate') ?>
    <?= $this->form->number('rate', array('rate' => $values['rate'] ?? ''), array(), array('step' => '0.01')) ?>

    <?= $this->form->label(t('Currency code'), 'currency_code') ?>
    <?= $this->form->text('currency_code', array('currency_code' => $values['currency']['code'] ?? 'USD')) ?>
    <?= $this->form->label(t('Currency symbol'), 'currency_symbol') ?>
    <?= $this->form->text('currency_symbol', array('currency_symbol' => $values['currency']['symbol'] ?? '$')) ?>

    <?= $this->form->label(t('Payment terms (days)'), 'terms_days') ?>
    <?= $this->form->number('terms_days', array('terms_days' => $values['terms_days'] ?? 30)) ?>
    <?= $this->form->label(t('Default notes / terms'), 'terms') ?>
    <?= $this->form->textarea('terms', array('terms' => $values['terms'] ?? '')) ?>

    <h3><?= t('Client') ?></h3>
    <p class="form-help"><?= t('Invoices for this project inherit these client details. You can override them on an individual invoice.') ?></p>
    <?= $this->form->label(t('Client name'), 'client_name') ?>
    <?= $this->form->text('client_name', array('client_name' => $values['client']['name'] ?? '')) ?>
    <?= $this->form->label(t('Client address'), 'client_address') ?>
    <?= $this->form->textarea('client_address', array('client_address' => $values['client']['address'] ?? '')) ?>
    <?= $this->form->label(t('Client email'), 'client_email') ?>
    <?= $this->form->text('client_email', array('client_email' => $values['client']['email'] ?? '')) ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save settings') ?></button>
        <?= $this->url->link(t('Back to invoices'), 'InvoiceController', 'project', array('plugin' => 'TimeInvoice', 'project_id' => $project['id'])) ?>
    </div>
</form>
```

- [x] **Step 5: Add routes and the sidebar link**

In `Plugin.php`, add to the route block:

```php
$this->route->addRoute('timeinvoice/project/settings', 'ProjectSettingsController', 'show', 'TimeInvoice');
$this->route->addRoute('timeinvoice/project/settings/save', 'ProjectSettingsController', 'save', 'TimeInvoice');
```

Replace `Template/invoice/sidebar.php` with:

```php
<li>
    <?= $this->url->link(t('Invoices'), 'InvoiceController', 'project', array('plugin' => 'TimeInvoice', 'project_id' => $project['id'])) ?>
</li>
<li>
    <?= $this->url->link(t('Invoice settings'), 'ProjectSettingsController', 'show', array('plugin' => 'TimeInvoice', 'project_id' => $project['id'])) ?>
</li>
```

- [x] **Step 6: Run test to verify it passes**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ProjectSettingsControllerTest.php --no-coverage
```

Expected: PASS (5 tests).

- [x] **Step 7: Run the full suite**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeInvoice
```

Expected: OK, 69 tests.

- [x] **Step 8: Commit**

```bash
git add Controller/ProjectSettingsController.php Template/invoice/project_settings.php Template/invoice/sidebar.php Plugin.php Test/ProjectSettingsControllerTest.php
git commit -m "feat: per-project invoice settings page

projectDefaults() read timeinvoice:defaults and freezeSnapshot() honoured it,
but nothing ever wrote the key. Adds a Project -> Invoice settings page owning
rate, currency, payment terms, default notes and the client block, gated on
admin-or-project-manager per the suite convention."
```

---

### Task 4: The invoice form inherits the project's client

Completes Q4 — the client belongs to the project, and the invoice inherits with an override.

**Files:**
- Modify: `Controller/InvoiceController.php:113-145` (`form()`)
- Modify: `Template/invoice/form.php:26-32`
- Test: `Test/DefaultsResolverTest.php` or `Test/InvoiceControllerTest.php`

**Interfaces:**
- Consumes: `projectDefaults(int $projectId): array` (existing), now populated by Task 3; `DefaultsResolver::resolve()` (existing).
- Produces: `$values['client']` in the form view is the project's client when the draft has none, and the draft's own when it has one.

- [ ] **Step 1: Write the failing test**

Add to `Test/InvoiceControllerTest.php`:

```php
public function testFormValuesInheritProjectClientAndAreOverriddenByDraft(): void
{
    $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);
    $this->container['projectMetadataModel']->save($pid, [
        'timeinvoice:defaults' => json_encode([
            'rate'   => 165.0,
            'client' => ['name' => 'Acme Inc', 'email' => 'ap@acme.example'],
        ]),
    ]);

    $c = new InvoiceController($this->container);
    $resolve = new ReflectionMethod($c, 'globalDefaults');
    $resolve->setAccessible(true);
    $pd = new ReflectionMethod($c, 'projectDefaults');
    $pd->setAccessible(true);

    // Project layer alone → the project's client wins over the (empty) global one.
    $merged = \Kanboard\Plugin\TimeInvoice\Model\DefaultsResolver::resolve(
        $resolve->invoke($c), $pd->invoke($c, $pid), []
    );
    $this->assertSame('Acme Inc', $merged['client']['name']);
    $this->assertSame(165.0, $merged['rate']);

    // A draft with its own client overrides the project's.
    $merged2 = \Kanboard\Plugin\TimeInvoice\Model\DefaultsResolver::resolve(
        $resolve->invoke($c), $pd->invoke($c, $pid), ['client' => ['name' => 'Beta LLC']]
    );
    $this->assertSame('Beta LLC', $merged2['client']['name'], 'draft client must override the project client');
}
```

Also add, to `Test/TemplateAssetsTest.php`:

```php
public function testFormLabelsClientAsInheritedFromProject(): void
{
    $src = file_get_contents($this->root() . '/Template/invoice/form.php');
    $this->assertStringContainsString('Inherited from the project', $src, 'the client block must say where its values come from');
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter 'testFormValuesInheritProjectClient|testFormLabelsClientAsInherited'
```

Expected: FAIL — `testFormLabelsClientAsInheritedFromProject` fails because the form has no such copy.

`testFormValuesInheritProjectClientAndAreOverriddenByDraft` may already pass: `DefaultsResolver` layers correctly and, after Task 3, `projectDefaults()` finally has data to return. That is the point — it pins behaviour Task 3 unlocked so a later change cannot silently break inheritance. If instead it FAILS with the project client clobbered by an empty global one, fix `DefaultsResolver::isEmpty()` to treat `['name'=>'','address'=>'','email'=>'']` as empty before continuing.

- [ ] **Step 3: Add a client-source hint to the form**

In `Template/invoice/form.php`, immediately before the `Client name` label, add:

```php
    <h3><?= t('Client') ?></h3>
    <p class="form-help"><?= t('Inherited from the project. Changes here apply to this invoice only.') ?></p>
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter 'testFormValuesInheritProjectClient|testFormLabelsClientAsInherited'
```

Expected: PASS (2 tests).

- [ ] **Step 5: Run the full suite**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeInvoice
```

Expected: OK, 71 tests.

- [ ] **Step 6: Commit**

```bash
git add Controller/InvoiceController.php Template/invoice/form.php Test/InvoiceControllerTest.php Test/TemplateAssetsTest.php
git commit -m "feat: invoice form inherits the project's client details

Pins the global < project < draft layering for the client block now that the
project settings page writes it, and labels the fields as inherited overrides."
```

---

### Task 5: Invoice show page

Delivers Q2's detail page — the surface every other action moves onto.

**Files:**
- Create: `Template/invoice/show.php`
- Modify: `Controller/InvoiceController.php` (add `show()`), `Plugin.php` (1 route)
- Test: `Test/InvoiceControllerTest.php`, `Test/TemplateAssetsTest.php`

**Interfaces:**
- Consumes: `snapshotForPdf(int $projectId, string $id, int $userId): array` (existing) — returns a live-computed snapshot with `status='draft'` and `number=null` for drafts, or the stored frozen record for sent/paid.
- Produces: route `timeinvoice/show` → `InvoiceController::show()`; template `TimeInvoice:invoice/show` receiving `project`, `invoice` (snapshot array), `status` (string), `invoice_id` (string).

- [ ] **Step 1: Write the failing tests**

Add to `Test/InvoiceControllerTest.php`:

```php
public function testShowRouteRegistered(): void
{
    $src = file_get_contents(dirname(__DIR__) . '/Plugin.php');
    $this->assertStringContainsString("'timeinvoice/show'", $src);
    $this->assertStringContainsString("'show'", $src);
}

public function testSnapshotForPdfDrivesShowForDraftAndFrozenForSent(): void
{
    $this->container['timeReportModel'] = fn ($x) => new class {
        public function report($pid, $s, $e, $g, $d, $u) {
            return ['breakdown' => [['key' => '1', 'label' => '#1 Task', 'hours' => 4.0, 'task_count' => 1]]];
        }
    };
    // Plugin::initialize() does not run under the harness, so the container has
    // no invoiceModel — snapshotForPdf() needs it. Register it explicitly.
    $this->container['invoiceModel'] = fn ($c) => new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($c);
    $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);
    $model = new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($this->container);
    $id = $model->createDraft($pid, 1, [
        'range' => ['start' => '2026-08-01', 'end' => '2026-08-31'],
        'granularity' => 'task', 'rate' => 100.0,
    ]);

    $c = new InvoiceController($this->container);
    $m = new ReflectionMethod($c, 'snapshotForPdf');
    $m->setAccessible(true);

    $draftSnap = $m->invoke($c, $pid, $id, 1);
    $this->assertSame('draft', $draftSnap['status']);
    $this->assertNull($draftSnap['number'], 'a draft has no number until issued');
    $this->assertSame(400.0, $draftSnap['total']);

    $frozen = $m->invoke($c, $pid, $id, 1);
    $model->send($pid, $id, $frozen);
    $sentSnap = $m->invoke($c, $pid, $id, 1);
    $this->assertSame('sent', $sentSnap['status']);
    $this->assertNotNull($sentSnap['number'], 'an issued invoice carries its frozen number');
}
```

Add to `Test/TemplateAssetsTest.php`:

```php
public function testShowTemplateExistsAndCarriesActions(): void
{
    $path = $this->root() . '/Template/invoice/show.php';
    $this->assertFileExists($path);
    $src = file_get_contents($path);
    foreach (["'pdf'", "'form'", "'send'", "'markPaid'", "'delete'"] as $action) {
        $this->assertStringContainsString($action, $src, "show page must offer $action");
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter 'testShowRouteRegistered|testShowTemplateExists|testSnapshotForPdfDrivesShow'
```

Expected: FAIL — route string absent, `Template/invoice/show.php` missing.

- [ ] **Step 3: Add the controller action**

In `Controller/InvoiceController.php`, after `project()`:

```php
/** Invoice detail page — the home for every per-invoice action. */
public function show(): void
{
    $userId = $this->userSession->getId();
    $projectId = $this->request->getIntegerParam('project_id');
    $id = $this->request->getStringParam('id');

    if (! in_array($projectId, $this->accessibleProjectIds($userId), true) || ! $this->hasTimeReport()) {
        $this->response->redirect($this->helper->url->to('InvoiceController', 'list', ['plugin' => 'TimeInvoice']));
        return;
    }

    $snap = $this->snapshotForPdf($projectId, $id, $userId);
    if ($snap === []) {
        $this->response->redirect($this->helper->url->to('InvoiceController', 'project', ['plugin' => 'TimeInvoice', 'project_id' => $projectId]));
        return;
    }

    $record = $this->invoiceModel->load($projectId, $id);
    $this->response->html($this->helper->layout->app('TimeInvoice:invoice/show', [
        'title'      => t('Invoice'),
        'project'    => $this->projectModel->getById($projectId),
        'invoice'    => $snap,
        'status'     => (string) ($record['status'] ?? 'draft'),
        'invoice_id' => $id,
    ]));
}
```

- [ ] **Step 4: Add the route**

In `Plugin.php`, after the `timeinvoice/form` route:

```php
$this->route->addRoute('timeinvoice/show', 'InvoiceController', 'show', 'TimeInvoice');
```

- [ ] **Step 5: Write the show template**

Create `Template/invoice/show.php`:

```php
<?php $cur = $invoice['currency'] ?? array('symbol' => '$'); ?>
<div class="page-header">
    <h2><?= $this->text->e($invoice['number'] ?? t('(draft)')) ?> — <?= $this->text->e($project['name']) ?></h2>
</div>

<p>
    <span class="<?= $this->helper->invoice->statusClass($status) ?>"><?= $this->helper->invoice->statusLabel($status) ?></span>
    &nbsp;·&nbsp; <?= t('Issued') ?>: <?= $this->text->e($invoice['issue_date'] ?? '') ?>
    &nbsp;·&nbsp; <?= t('Due') ?>: <?= $this->text->e($invoice['due_date'] ?? '') ?>
</p>

<div class="timeinvoice-actions">
    <?= $this->url->link(t('Download PDF'), 'InvoiceController', 'pdf', array('plugin' => 'TimeInvoice', 'project_id' => $project['id'], 'id' => $invoice_id), false, 'btn') ?>
    <?= $this->url->link(t('View PDF'), 'InvoiceController', 'pdf', array('plugin' => 'TimeInvoice', 'project_id' => $project['id'], 'id' => $invoice_id, 'inline' => 1), false, 'btn') ?>
    <?php if ($status === 'draft'): ?>
        <?= $this->url->link(t('Edit'), 'InvoiceController', 'form', array('plugin' => 'TimeInvoice', 'project_id' => $project['id'], 'id' => $invoice_id), false, 'btn') ?>
        <?= $this->url->link(t('Issue'), 'InvoiceController', 'send', array('plugin' => 'TimeInvoice', 'project_id' => $project['id'], 'id' => $invoice_id), true, 'btn btn-blue timeinvoice-send') ?>
        <?= $this->url->link(t('Delete'), 'InvoiceController', 'delete', array('plugin' => 'TimeInvoice', 'project_id' => $project['id'], 'id' => $invoice_id), true, 'btn btn-red timeinvoice-delete') ?>
    <?php elseif ($status === 'sent'): ?>
        <?= $this->url->link(t('Mark paid'), 'InvoiceController', 'markPaid', array('plugin' => 'TimeInvoice', 'project_id' => $project['id'], 'id' => $invoice_id), true, 'btn btn-blue') ?>
    <?php endif ?>
    <?= $this->url->link(t('Back to invoices'), 'InvoiceController', 'project', array('plugin' => 'TimeInvoice', 'project_id' => $project['id'])) ?>
</div>

<div class="timeinvoice-parties">
    <div>
        <h3><?= t('From') ?></h3>
        <p><?= nl2br($this->text->e(trim(($invoice['business']['name'] ?? '') . "\n" . ($invoice['business']['address'] ?? '') . "\n" . ($invoice['business']['email'] ?? '')))) ?></p>
    </div>
    <div>
        <h3><?= t('Bill to') ?></h3>
        <p><?= nl2br($this->text->e(trim(($invoice['client']['name'] ?? '') . "\n" . ($invoice['client']['address'] ?? '') . "\n" . ($invoice['client']['email'] ?? '')))) ?></p>
    </div>
</div>

<h3><?= t('Line items') ?></h3>
<table class="table-striped">
    <tr>
        <th><?= t('Description') ?></th>
        <th><?= t('Hours') ?></th>
        <th><?= t('Rate') ?></th>
        <th><?= t('Amount') ?></th>
    </tr>
    <?php foreach ($invoice['line_items'] ?? array() as $li): ?>
        <tr>
            <td><?= $this->text->e($li['label']) ?></td>
            <td><?= number_format((float) $li['hours'], 2) ?></td>
            <td><?= $this->helper->invoice->money((float) ($invoice['rate'] ?? 0), $cur) ?></td>
            <td><?= $this->helper->invoice->money((float) $li['amount'], $cur) ?></td>
        </tr>
    <?php endforeach ?>
    <tr>
        <td colspan="3"><?= t('Subtotal') ?></td>
        <td><?= $this->helper->invoice->money((float) ($invoice['subtotal'] ?? 0), $cur) ?></td>
    </tr>
    <?php if (! empty($invoice['tax']['enabled'])): ?>
        <tr>
            <td colspan="3"><?= t('Tax') ?> (<?= $this->text->e(rtrim(rtrim(number_format((float) $invoice['tax']['rate'], 3), '0'), '.')) ?>%)</td>
            <td><?= $this->helper->invoice->money((float) $invoice['tax']['amount'], $cur) ?></td>
        </tr>
    <?php endif ?>
    <tr class="timeinvoice-total-row">
        <td colspan="3"><?= t('Total') ?></td>
        <td><?= $this->helper->invoice->money((float) ($invoice['total'] ?? 0), $cur) ?></td>
    </tr>
</table>

<?php if (! empty($invoice['notes'])): ?>
    <h3><?= t('Notes / terms') ?></h3>
    <p><?= nl2br($this->text->e($invoice['notes'])) ?></p>
<?php endif ?>
```

- [ ] **Step 6: Style the show page**

Append to `Assets/css/timeinvoice.css`:

```css
.timeinvoice-actions { margin: 12px 0; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.timeinvoice-parties { display: flex; gap: 32px; flex-wrap: wrap; }
.timeinvoice-parties > div { min-width: 220px; }
```

- [ ] **Step 7: Run tests to verify they pass**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter 'testShowRouteRegistered|testShowTemplateExists|testSnapshotForPdfDrivesShow'
```

Expected: PASS (3 tests).

- [ ] **Step 8: Run the full suite**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeInvoice
```

Expected: OK, 74 tests.

- [ ] **Step 9: Commit**

```bash
git add Controller/InvoiceController.php Template/invoice/show.php Plugin.php Assets/css/timeinvoice.css Test/InvoiceControllerTest.php Test/TemplateAssetsTest.php
git commit -m "feat: invoice show page

An invoice had no detail page; every action was crammed into a table cell and
the only way to look at one was to download a PDF. Reuses snapshotForPdf() so a
draft shows live figures and a sent/paid invoice shows its frozen snapshot."
```

---

### Task 6: Inline PDF

Delivers the second half of Q2 — look at the PDF without downloading it.

**Files:**
- Modify: `Controller/InvoiceController.php:281-303` (`pdf()`)
- Test: `Test/InvoiceControllerTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `protected function contentDisposition(bool $inline, string $name): string` — a pure seam returning the full header value. `pdf()` accepts an `inline=1` query parameter.

- [ ] **Step 1: Write the failing test**

Add to `Test/InvoiceControllerTest.php`:

```php
public function testContentDispositionSwitchesOnInlineFlag(): void
{
    $c = new InvoiceController($this->container);
    $m = new ReflectionMethod($c, 'contentDisposition');
    $m->setAccessible(true);
    $this->assertSame('attachment; filename="INV-2026-001.pdf"', $m->invoke($c, false, 'INV-2026-001.pdf'));
    $this->assertSame('inline; filename="INV-2026-001.pdf"', $m->invoke($c, true, 'INV-2026-001.pdf'));
    $this->assertSame('inline; filename="draft.pdf"', $m->invoke($c, true, 'draft.pdf'));
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter testContentDispositionSwitchesOnInlineFlag
```

Expected: FAIL with `ReflectionException: Method contentDisposition does not exist`.

- [ ] **Step 3: Add the seam and wire it into `pdf()`**

In `Controller/InvoiceController.php`, add above `pdf()`:

```php
/**
 * Content-Disposition for the PDF response. `inline` lets the browser's own
 * viewer render the invoice instead of forcing a download — the show page's
 * "View PDF" action. Pure seam so the choice is unit-testable without HTTP.
 */
protected function contentDisposition(bool $inline, string $name): string
{
    return ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"';
}
```

In `pdf()`, replace the disposition line:

```php
$inline = $this->request->getIntegerParam('inline') === 1;

$this->response->withoutCache();
$this->response->withContentType('application/pdf');
$this->response->withHeader('Content-Disposition', $this->contentDisposition($inline, $name));
$this->response->withBody($bytes);
$this->response->send();
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter testContentDispositionSwitchesOnInlineFlag
```

Expected: PASS (1 test, 3 assertions).

- [ ] **Step 5: Run the full suite**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeInvoice
```

Expected: OK, 75 tests.

- [ ] **Step 6: Commit**

```bash
git add Controller/InvoiceController.php Test/InvoiceControllerTest.php
git commit -m "feat: inline PDF view

pdf() hardcoded Content-Disposition: attachment, so every look at an invoice
was a file download. Adds an inline=1 flag behind a pure, testable seam."
```

---

### Task 7: Collapse the list row onto the show page

Delivers Q9.

**Files:**
- Modify: `Template/invoice/list.php:14-40`
- Test: `Test/TemplateAssetsTest.php`

**Interfaces:**
- Consumes: route `timeinvoice/show` (Task 5).
- Produces: the list's only per-row link is the invoice number → show page.

- [ ] **Step 1: Write the failing test**

Add to `Test/TemplateAssetsTest.php`:

```php
public function testListRowCollapsesToShowPage(): void
{
    $src = file_get_contents($this->root() . '/Template/invoice/list.php');
    $this->assertStringContainsString("'show'", $src, 'the number must link to the show page');
    foreach (["'markPaid'", "'delete'", "'send'"] as $action) {
        $this->assertStringNotContainsString($action, $src, "$action must live on the show page, not in a list row");
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter testListRowCollapsesToShowPage
```

Expected: FAIL — `'markPaid'` is still present in the row actions.

- [ ] **Step 3: Rewrite the table**

In `Template/invoice/list.php`, replace the whole `<table>` block with:

```php
        <table class="table-striped">
            <tr>
                <th><?= t('Number') ?></th><th><?= t('Status') ?></th>
                <th><?= t('Issued') ?></th><th><?= t('Total') ?></th>
            </tr>
            <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td>
                        <?= $this->url->link(
                            $inv['number'] ?? t('(draft)'),
                            'InvoiceController', 'show',
                            array('plugin' => 'TimeInvoice', 'project_id' => $inv['project_id'], 'id' => $inv['id'])
                        ) ?>
                    </td>
                    <td class="<?= $this->helper->invoice->statusClass($inv['status'] ?? '') ?>"><?= $this->helper->invoice->statusLabel($inv['status'] ?? '') ?></td>
                    <td><?= $this->text->e($inv['issue_date'] ?? $inv['created_at'] ?? '') ?></td>
                    <td><?= $this->helper->invoice->money((float) ($inv['total'] ?? 0), $inv['currency'] ?? array('symbol' => '$')) ?></td>
                </tr>
            <?php endforeach ?>
        </table>
```

`$this->url->link()` escapes its label, so an invoice number needs no separate `text->e()`.

- [ ] **Step 4: Run test to verify it passes**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter testListRowCollapsesToShowPage
```

Expected: PASS.

- [ ] **Step 5: Run the full suite**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeInvoice
```

Expected: OK, 76 tests.

- [ ] **Step 6: Commit**

```bash
git add Template/invoice/list.php Test/TemplateAssetsTest.php
git commit -m "refactor: collapse invoice list rows onto the show page

The list's job is to find an invoice and see its status. Destructive actions
belong behind a deliberate navigation rather than one mis-click in a dense
table, so Issue/Delete/Mark-paid now live only on the show page."
```

---

### Task 8: Send → Issue

Delivers Q1's rename and Q17. The route, the controller method and the stored `'sent'` value all stay — only user-visible strings change.

**Files:**
- Modify: `Helper/InvoiceHelper.php:15-22`, `Assets/js/timeinvoice.js:13-17`
- Test: `Test/InvoiceHelperTest.php`, `Test/TemplateAssetsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `InvoiceHelper::statusLabel('sent')` returns `t('Issued')`. The stored status value is unchanged.

- [ ] **Step 1: Write the failing tests**

Add to `Test/InvoiceHelperTest.php`:

```php
public function testSentStatusRendersAsIssued(): void
{
    $h = new \Kanboard\Plugin\TimeInvoice\Helper\InvoiceHelper($this->container);
    $this->assertSame('Issued', $h->statusLabel('sent'), 'no email is sent; the state is "issued"');
    $this->assertSame('timeinvoice-status-sent', $h->statusClass('sent'), 'CSS class keeps the stored value');
    $this->assertSame('Draft', $h->statusLabel('draft'));
    $this->assertSame('Paid', $h->statusLabel('paid'));
}
```

Add to `Test/TemplateAssetsTest.php`:

```php
public function testNoSendWordingRemainsInUi(): void
{
    $js = file_get_contents($this->root() . '/Assets/js/timeinvoice.js');
    $this->assertStringNotContainsString('Send this invoice?', $js, 'confirm copy must not promise an email');
    $this->assertStringContainsString('Issue this invoice?', $js);
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter 'testSentStatusRendersAsIssued|testNoSendWordingRemainsInUi'
```

Expected: FAIL — `statusLabel('sent')` returns `'Sent'`; the JS confirm still says "Send this invoice?".

- [ ] **Step 3: Relabel the status**

In `Helper/InvoiceHelper.php`:

```php
public function statusLabel(string $status): string
{
    return match ($status) {
        'draft' => t('Draft'),
        // No email is ever sent (the mail seam cannot attach a PDF), so the
        // state is "issued". The stored value stays 'sent' — relabelling is a
        // presentation change, not a data migration.
        'sent'  => t('Issued'),
        'paid'  => t('Paid'),
        default => ucfirst($status),
    };
}
```

- [ ] **Step 4: Fix the confirm copy**

In `Assets/js/timeinvoice.js`, replace the send handler's confirm string:

```js
    // Confirm the issue transition (freezes the snapshot + assigns a number).
    jQuery(document).on("click", ".timeinvoice-send", function (e) {
        if (!window.confirm("Issue this invoice? Its number and totals will be locked.")) {
            e.preventDefault();
        }
    });
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter 'testSentStatusRendersAsIssued|testNoSendWordingRemainsInUi'
```

Expected: PASS (2 tests).

- [ ] **Step 6: Run the full suite**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeInvoice
```

Expected: OK, 78 tests. If an existing assertion expects `'Sent'`, update it — the rename is the point.

- [ ] **Step 7: Commit**

```bash
git add Helper/InvoiceHelper.php Assets/js/timeinvoice.js Test/InvoiceHelperTest.php Test/TemplateAssetsTest.php
git commit -m "fix: rename Send to Issue — the plugin never sent anything

send() froze a snapshot and assigned a number; no email was ever dispatched,
and Kanboard's mail seam cannot attach a PDF anyway. Relabels the action and
the 'sent' status as Issued. Stored values and routes are unchanged."
```

---

### Task 9: Per-invoice payment terms, and delete the dead currency field

Delivers Q16 and fixes audit finding A11.

**Files:**
- Modify: `Controller/InvoiceController.php:155-165` (`buildDraftFromRequest`), `:183-215` (`freezeSnapshot`)
- Modify: `Template/invoice/form.php`
- Test: `Test/InvoiceControllerTest.php`

**Interfaces:**
- Consumes: `projectDefaults()`, `globalDefaults()` (existing).
- Produces: `buildDraftFromRequest()` no longer emits a `currency` key. `freezeSnapshot()` resolves `terms_days` as draft → project → global, and currency as project → global.

- [ ] **Step 1: Write the failing test**

Add to `Test/InvoiceControllerTest.php`:

```php
public function testDraftTermsDaysOverridesProjectAndGlobal(): void
{
    $this->container['timeReportModel'] = fn ($x) => new class {
        public function report($pid, $s, $e, $g, $d, $u) {
            return ['breakdown' => [['key' => '1', 'label' => '#1', 'hours' => 1.0, 'task_count' => 1]]];
        }
    };
    $this->container['configModel']->save([
        'timeinvoice_terms_days' => '30',
        'timeinvoice_currency'   => json_encode(['code' => 'USD', 'symbol' => '$']),
    ]);
    $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);
    $this->container['projectMetadataModel']->save($pid, [
        'timeinvoice:defaults' => json_encode([
            'terms_days' => 20,
            'currency'   => ['code' => 'GBP', 'symbol' => '£'],
        ]),
    ]);

    $c = new InvoiceController($this->container);
    $m = new ReflectionMethod($c, 'freezeSnapshot');
    $m->setAccessible(true);

    $base = [
        'project_id' => $pid,
        'range' => ['start' => '2026-08-01', 'end' => '2026-08-31'],
        'granularity' => 'task', 'rate' => 100.0, 'issue_date' => '2026-08-01',
    ];

    // No draft override → project layer wins (20 days).
    $snap = $m->invoke($c, $base, 1);
    $this->assertSame('2026-08-21', $snap['due_date'], 'project terms_days = 20');
    $this->assertSame('GBP', $snap['currency']['code'], 'currency stays project-level');

    // Draft override → 7 days.
    $snap2 = $m->invoke($c, array_merge($base, ['terms_days' => 7]), 1);
    $this->assertSame('2026-08-08', $snap2['due_date'], 'draft terms_days must override the project default');
    $this->assertSame('GBP', $snap2['currency']['code'], 'a draft must NOT be able to change currency');
}

public function testBuildDraftFromRequestNoLongerEmitsDeadCurrencyKey(): void
{
    $c = new InvoiceController($this->container);
    $m = new ReflectionMethod($c, 'buildDraftFromRequest');
    $m->setAccessible(true);
    $draft = $m->invoke($c, ['start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'rate' => '100', 'terms_days' => '14']);
    $this->assertArrayNotHasKey('currency', $draft, 'the form never posts currency; storing USD/$ on every draft was a lie');
    $this->assertSame(14, $draft['terms_days']);
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter 'testDraftTermsDaysOverrides|testBuildDraftFromRequestNoLongerEmits'
```

Expected: FAIL — `freezeSnapshot()` ignores the draft's `terms_days`, and `buildDraftFromRequest()` still emits `currency`.

- [ ] **Step 3: Drop the dead currency key**

In `buildDraftFromRequest()`, delete the `'currency' => [...]` line entirely. The remaining array is unchanged.

- [ ] **Step 4: Honour the draft's terms_days at freeze**

In `freezeSnapshot()`, replace the currency/terms resolution block and its stale comment:

```php
    /**
     * Assemble the immutable snapshot frozen onto an issued invoice.
     *
     * Currency is project-level only (global < project): billing one client in
     * two currencies is not a real scenario, and the form does not offer it.
     * terms_days layers global < project < draft, because a rush Net-15 on a
     * single invoice IS a real scenario.
     */
    protected function freezeSnapshot(array $draft, int $userId): array
    {
        $projectId = (int) $draft['project_id'];
        $issueDate = $draft['issue_date'] ?? date('Y-m-d');
        $rate      = (float) ($draft['rate'] ?? 0);

        $global  = $this->globalDefaults();
        $project = $this->projectDefaults($projectId);

        $currency  = $project['currency'] ?? ($global['currency'] ?? ['code' => 'USD', 'symbol' => '$']);
        $termsDays = (int) ($draft['terms_days']
            ?? $project['terms_days']
            ?? $global['terms_days']
            ?? 30);
```

The rest of the method body is unchanged.

- [ ] **Step 5: Put terms on the form**

In `Template/invoice/form.php`, after the tax fields and before the `Client` heading:

```php
    <?= $this->form->label(t('Payment terms (days)'), 'terms_days') ?>
    <?= $this->form->number('terms_days', $values) ?>
    <p class="form-help"><?= t('Overrides the project default for this invoice only.') ?></p>
```

- [ ] **Step 6: Run tests to verify they pass**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter 'testDraftTermsDaysOverrides|testBuildDraftFromRequestNoLongerEmits'
```

Expected: PASS (2 tests).

- [ ] **Step 7: Run the full suite**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeInvoice
```

Expected: OK, 80 tests. `testFreezeSnapshotBuildsLineItemsAndTotals` still passes — its draft has no project defaults, so it falls through to the global 30-day default as before.

- [ ] **Step 8: Commit**

```bash
git add Controller/InvoiceController.php Template/invoice/form.php Test/InvoiceControllerTest.php
git commit -m "feat: per-invoice payment terms; drop the dead currency field

buildDraftFromRequest() read currency_code/currency_symbol that the form never
posted, so every draft persisted USD/\$ regardless of settings — invisible only
because freezeSnapshot() ignored the stored values. Deletes that field, and
makes terms_days a real per-invoice override layered over project and global."
```

---

### Task 10: Live totals on the draft form

Delivers Q5 and fixes audit finding A9.

**Files:**
- Modify: `Controller/InvoiceController.php` (add `totalsPayload()` + `previewTotals()`), `Plugin.php` (1 route)
- Modify: `Template/invoice/form.php`, `Assets/js/timeinvoice.js`, `Assets/css/timeinvoice.css`
- Test: `Test/InvoiceControllerTest.php`, `Test/TemplateAssetsTest.php`

**Interfaces:**
- Consumes: `buildDraftFromRequest(array $v): array`, `freezeSnapshot(array $draft, int $userId): array`, `requestProjectId(array $values): int` (all existing).
- Produces: `protected function totalsPayload(array $snapshot): array` returning `['hours'=>float,'line_count'=>int,'subtotal'=>float,'tax'=>float,'total'=>float,'symbol'=>string]`; route `timeinvoice/preview-totals` → `InvoiceController::previewTotals()` responding JSON.

- [ ] **Step 1: Write the failing test**

Add to `Test/InvoiceControllerTest.php`:

```php
public function testTotalsPayloadSummarisesASnapshot(): void
{
    $c = new InvoiceController($this->container);
    $m = new ReflectionMethod($c, 'totalsPayload');
    $m->setAccessible(true);

    $snap = [
        'line_items' => [
            ['label' => 'A', 'hours' => 2.5, 'amount' => 250.0],
            ['label' => 'B', 'hours' => 1.5, 'amount' => 150.0],
        ],
        'subtotal' => 400.0,
        'tax'      => ['enabled' => true, 'rate' => 10.0, 'amount' => 40.0],
        'total'    => 440.0,
        'currency' => ['code' => 'GBP', 'symbol' => '£'],
    ];

    $p = $m->invoke($c, $snap);
    $this->assertSame(4.0, $p['hours']);
    $this->assertSame(2, $p['line_count']);
    $this->assertSame(400.0, $p['subtotal']);
    $this->assertSame(40.0, $p['tax']);
    $this->assertSame(440.0, $p['total']);
    $this->assertSame('£', $p['symbol']);
}

public function testTotalsPayloadHandlesEmptyRange(): void
{
    $c = new InvoiceController($this->container);
    $m = new ReflectionMethod($c, 'totalsPayload');
    $m->setAccessible(true);
    $p = $m->invoke($c, ['line_items' => [], 'subtotal' => 0.0, 'tax' => ['enabled' => false, 'amount' => 0.0], 'total' => 0.0]);
    $this->assertSame(0.0, $p['hours']);
    $this->assertSame(0, $p['line_count']);
    $this->assertSame('$', $p['symbol'], 'missing currency falls back to $');
}
```

Add to `Test/TemplateAssetsTest.php`:

```php
public function testFormAndJsCarryTheLiveTotalsRegion(): void
{
    $tpl = file_get_contents($this->root() . '/Template/invoice/form.php');
    $this->assertStringContainsString('timeinvoice-totals', $tpl, 'form needs a totals region to fill');
    $this->assertStringContainsString("'previewTotals'", $tpl, 'form must carry the endpoint URL as data');

    $js = file_get_contents($this->root() . '/Assets/js/timeinvoice.js');
    $this->assertStringContainsString('timeinvoice-totals', $js);
    $this->assertStringContainsString('timeinvoice-recalc', $js, 'inputs that trigger a recalc are marked with a class');
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter 'testTotalsPayload|testFormAndJsCarryTheLiveTotals'
```

Expected: FAIL — `totalsPayload` does not exist; the form has no totals region.

- [ ] **Step 3: Add the payload seam and the endpoint**

In `Controller/InvoiceController.php`, after `freezeSnapshot()`:

```php
/**
 * Condense a snapshot into the numbers the draft form shows live.
 * Pure seam — unit-testable without a request or TimeReport.
 *
 * @return array{hours:float,line_count:int,subtotal:float,tax:float,total:float,symbol:string}
 */
protected function totalsPayload(array $snapshot): array
{
    $items = $snapshot['line_items'] ?? [];
    return [
        'hours'      => round((float) array_sum(array_column($items, 'hours')), 2),
        'line_count' => count($items),
        'subtotal'   => (float) ($snapshot['subtotal'] ?? 0.0),
        'tax'        => (float) ($snapshot['tax']['amount'] ?? 0.0),
        'total'      => (float) ($snapshot['total'] ?? 0.0),
        'symbol'     => (string) ($snapshot['currency']['symbol'] ?? '$'),
    ];
}

/**
 * POST — live totals for the draft form. Mirrors generateCoverNote()'s seam:
 * the form AJAXes $form.serialize(), so project_id arrives in the BODY.
 */
public function previewTotals(): void
{
    $this->checkCSRFForm();
    $userId = $this->userSession->getId();
    // getValues() is single-use/stateful — read it ONCE.
    $values = $this->request->getValues();
    $projectId = $this->requestProjectId($values);

    if (! in_array($projectId, $this->accessibleProjectIds($userId), true) || ! $this->hasTimeReport()) {
        $this->response->json(['error' => t('Not available for this project.')], 400);
        return;
    }

    $draft = array_merge($this->buildDraftFromRequest($values), ['project_id' => $projectId]);
    $this->response->json($this->totalsPayload($this->freezeSnapshot($draft, $userId)));
}
```

- [ ] **Step 4: Add the route**

In `Plugin.php`, beside the `generate-note` route:

```php
$this->route->addRoute('timeinvoice/preview-totals', 'InvoiceController', 'previewTotals', 'TimeInvoice');
```

- [ ] **Step 5: Add the totals region to the form**

In `Template/invoice/form.php`, add the region just above `<div class="form-actions">`. The fields that trigger a recalc are named in a data attribute rather than each carrying a class, because `$this->form->number()` and `$this->form->select()` do not take a class argument in the signatures used here:

```php
    <div class="timeinvoice-totals timeinvoice-recalc"
         data-url="<?= $this->url->href('InvoiceController', 'previewTotals', array('plugin' => 'TimeInvoice')) ?>"
         data-recalc-fields="start_date,end_date,granularity,rate,tax_enabled,tax_rate">
        <span class="timeinvoice-totals-idle"><?= t('Totals update as you change the range, grouping, rate or tax.') ?></span>
    </div>
```

- [ ] **Step 6: Add the delegated handler**

Append inside the IIFE in `Assets/js/timeinvoice.js`:

```js
    // Live draft totals. Debounced; reuses the same $form.serialize() seam as
    // the cover note, so project_id arrives in the POST body.
    var totalsTimer = null;
    function refreshTotals() {
        var $box = jQuery(".timeinvoice-totals");
        if (!$box.length) { return; }
        var $form = $box.closest("form");
        $box.addClass("timeinvoice-loading");
        jQuery.ajax({
            url: $box.data("url"),
            method: "POST",
            dataType: "json",
            data: $form.serialize()
        }).done(function (r) {
            if (!r || r.error) {
                $box.text(r && r.error ? r.error : "Could not calculate totals.");
                return;
            }
            $box.html(
                "<strong>" + r.hours.toFixed(2) + "</strong> hours · " +
                r.line_count + " line item(s) · " +
                "Subtotal " + r.symbol + r.subtotal.toFixed(2) +
                (r.tax ? " · Tax " + r.symbol + r.tax.toFixed(2) : "") +
                " · <strong>Total " + r.symbol + r.total.toFixed(2) + "</strong>"
            );
        }).fail(function () {
            $box.text("Could not calculate totals.");
        }).always(function () {
            $box.removeClass("timeinvoice-loading");
        });
    }

    // Delegated from document, scoped to the form that owns a totals region, so
    // the handler is inert on every other page.
    jQuery(document).on("change keyup", "form :input", function () {
        var $box = jQuery(".timeinvoice-totals");
        if (!$box.length) { return; }
        var fields = String($box.data("recalc-fields") || "").split(",");
        if (jQuery.inArray(this.name, fields) === -1) { return; }
        window.clearTimeout(totalsTimer);
        totalsTimer = window.setTimeout(refreshTotals, 400);
    });

    jQuery(function () {
        if (jQuery(".timeinvoice-totals").length) { refreshTotals(); }
    });
```

- [ ] **Step 7: Style it**

Append to `Assets/css/timeinvoice.css`:

```css
.timeinvoice-totals { margin: 12px 0; padding: 8px 12px; border-left: 3px solid #268bd2; background: rgba(38,139,210,0.06); }
.timeinvoice-totals-idle { opacity: 0.7; }
```

- [ ] **Step 8: Run tests to verify they pass**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter 'testTotalsPayload|testFormAndJsCarryTheLiveTotals'
```

Expected: PASS (3 tests).

- [ ] **Step 9: Verify in a real browser**

Start the dev stack, open a project's New invoice form, change the rate, and confirm the totals strip updates without a page reload:

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing && docker compose -f docker-compose.dev.yml up -d
```

Then visit http://localhost:8081 (admin/admin). This step has no automated substitute — the endpoint is unit-tested, the wiring is not.

- [ ] **Step 10: Run the full suite**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeInvoice
```

Expected: OK, 83 tests.

- [ ] **Step 11: Commit**

```bash
git add Controller/InvoiceController.php Plugin.php Template/invoice/form.php Assets/js/timeinvoice.js Assets/css/timeinvoice.css Test/InvoiceControllerTest.php Test/TemplateAssetsTest.php
git commit -m "feat: live totals on the draft invoice form

The form asked for an hourly rate with no indication of how many hours were in
the range — no subtotal was visible until you saved, downloaded a PDF and
opened it. Adds a JSON endpoint reusing freezeSnapshot() and a debounced
delegated handler, mirroring the cover-note AJAX seam."
```

---

### Task 11: Under-billing warning banner

Delivers Q11 — the read-only half of the A10 fix. The real "Bill hours for" control is 1.3.0.

**Files:**
- Create: `Template/invoice/_banner.php`
- Modify: `Controller/InvoiceController.php` (add `unbilledParticipants()`), `form()` and `show()` params
- Modify: `Template/invoice/form.php`, `Template/invoice/show.php`, `Assets/css/timeinvoice.css`
- Test: `Test/InvoiceControllerTest.php`

**Interfaces:**
- Consumes: `TimeReportModel::participants(int $projectId, string $start, string $end, int $requestingUserId): array<int,array{name:string,hours:float}>`; `TimeReportModel::canReportOnOthers(int $projectId, int $userId): bool` (both public).
- Produces: `protected function unbilledParticipants(int $projectId, string $start, string $end, int $userId): array` returning `['visible'=>bool, 'specific'=>bool, 'people'=>int, 'hours'=>float]`.

**Design note:** a non-manager cannot see other people's hours, so the banner can only be generic for them. To avoid a permanently-on banner, the generic form is shown **only when the project has more than one assignable member** — cheap, ungated, and it keeps solo projects silent.

- [ ] **Step 1: Write the failing test**

Add to `Test/InvoiceControllerTest.php`:

```php
public function testUnbilledParticipantsReportsOthersForAManager(): void
{
    $this->container['timeReportModel'] = fn ($x) => new class {
        public function canReportOnOthers($pid, $uid) { return true; }
        public function participants($pid, $s, $e, $uid) {
            return [
                1 => ['name' => 'Me', 'hours' => 10.0],
                2 => ['name' => 'Other', 'hours' => 14.0],
                3 => ['name' => 'Third', 'hours' => 8.5],
            ];
        }
    };
    $c = new InvoiceController($this->container);
    $m = new ReflectionMethod($c, 'unbilledParticipants');
    $m->setAccessible(true);
    $r = $m->invoke($c, 5, '2026-08-01', '2026-08-31', 1);

    $this->assertTrue($r['visible']);
    $this->assertTrue($r['specific']);
    $this->assertSame(2, $r['people'], 'self is excluded from the unbilled count');
    $this->assertSame(22.5, $r['hours']);
}

public function testUnbilledParticipantsSilentWhenBillingIsAlreadyComplete(): void
{
    $this->container['timeReportModel'] = fn ($x) => new class {
        public function canReportOnOthers($pid, $uid) { return true; }
        public function participants($pid, $s, $e, $uid) { return [1 => ['name' => 'Me', 'hours' => 10.0]]; }
    };
    $c = new InvoiceController($this->container);
    $m = new ReflectionMethod($c, 'unbilledParticipants');
    $m->setAccessible(true);
    $this->assertFalse($m->invoke($c, 5, '2026-08-01', '2026-08-31', 1)['visible'], 'solo biller → no banner');
}

public function testUnbilledParticipantsIsGenericForANonManager(): void
{
    $this->container['timeReportModel'] = fn ($x) => new class {
        public function canReportOnOthers($pid, $uid) { return false; }
        public function participants($pid, $s, $e, $uid) { return [1 => ['name' => 'Me', 'hours' => 10.0]]; }
    };
    $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);
    // Two members → the project is genuinely multi-person. NOTE: addUser()
    // returns false for a user id that does not exist, which would leave the
    // project memberless and pass this test for the wrong reason (see Task 3).
    $um = new \Kanboard\Model\UserModel($this->container);
    $this->container['projectUserRoleModel']->addUser($pid, 1, \Kanboard\Core\Security\Role::PROJECT_MANAGER);
    $this->container['projectUserRoleModel']->addUser($pid, $um->create(['username' => 'other', 'name' => 'Other']), \Kanboard\Core\Security\Role::PROJECT_MEMBER);

    $c = new InvoiceController($this->container);
    $m = new ReflectionMethod($c, 'unbilledParticipants');
    $m->setAccessible(true);
    $r = $m->invoke($c, $pid, '2026-08-01', '2026-08-31', 1);

    $this->assertTrue($r['visible'], 'a non-manager on a multi-person project gets the generic warning');
    $this->assertFalse($r['specific'], 'they may not see names or hours');
}

public function testUnbilledParticipantsSilentWithoutTimeReport(): void
{
    $c = new InvoiceController($this->container);
    $m = new ReflectionMethod($c, 'unbilledParticipants');
    $m->setAccessible(true);
    $this->assertFalse($m->invoke($c, 5, '2026-08-01', '2026-08-31', 1)['visible']);
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter testUnbilledParticipants
```

Expected: FAIL with `ReflectionException: Method unbilledParticipants does not exist`.

- [ ] **Step 3: Implement the check**

In `Controller/InvoiceController.php`, after `accessibleProjects()`:

```php
/**
 * TimeInvoice bills only the requesting user's hours: report() is called with
 * six positional arguments, so its $subjectUserIds/$allUsers default to
 * self-only. Until 1.3.0 ships the "Bill hours for" control, warn rather than
 * under-bill silently.
 *
 * A non-manager cannot see other people's hours (TimeReport gates this), so
 * they get a generic warning — and only on a genuinely multi-person project,
 * otherwise the banner would be permanently on.
 *
 * @return array{visible:bool,specific:bool,people:int,hours:float}
 */
protected function unbilledParticipants(int $projectId, string $start, string $end, int $userId): array
{
    $silent = ['visible' => false, 'specific' => false, 'people' => 0, 'hours' => 0.0];

    if (! $this->hasTimeReport()) {
        return $silent;
    }
    $model = $this->timeReportModel;
    if (! method_exists($model, 'participants') || ! method_exists($model, 'canReportOnOthers')) {
        return $silent;
    }

    try {
        if (! $model->canReportOnOthers($projectId, $userId)) {
            $members = $this->projectUserRoleModel->getAssignableUsers($projectId);
            return count($members) > 1
                ? ['visible' => true, 'specific' => false, 'people' => 0, 'hours' => 0.0]
                : $silent;
        }

        $all = $model->participants($projectId, $start, $end, $userId);
    } catch (\Throwable $e) {
        return $silent;
    }

    unset($all[$userId]);
    if ($all === []) {
        return $silent;
    }

    return [
        'visible'  => true,
        'specific' => true,
        'people'   => count($all),
        'hours'    => round((float) array_sum(array_column($all, 'hours')), 2),
    ];
}
```

- [ ] **Step 4: Write the banner partial**

Create `Template/invoice/_banner.php`:

```php
<?php if (! empty($unbilled['visible'])): ?>
    <div class="alert alert-error timeinvoice-unbilled">
        <?php if (! empty($unbilled['specific'])): ?>
            <?= t('%d other people logged %s hours in this range that are not on this invoice.', (int) $unbilled['people'], number_format((float) $unbilled['hours'], 2)) ?>
        <?php else: ?>
            <?= t('You may not be seeing all billable hours on this project.') ?>
        <?php endif ?>
        <?= t('This invoice bills only your own hours.') ?>
    </div>
<?php endif ?>
```

- [ ] **Step 5: Render it on both pages**

In `form()`, add to the `html()` params:

```php
'unbilled' => $this->unbilledParticipants(
    $projectId,
    (string) $values['start_date'],
    (string) $values['end_date'],
    $userId
),
```

In `show()`, add:

```php
'unbilled' => $this->unbilledParticipants(
    $projectId,
    (string) ($snap['range']['start'] ?? date('Y-m-01')),
    (string) ($snap['range']['end'] ?? date('Y-m-d')),
    $userId
),
```

In both `Template/invoice/form.php` and `Template/invoice/show.php`, immediately after the `page-header` div:

```php
<?= $this->render('TimeInvoice:invoice/_banner', array('unbilled' => $unbilled)) ?>
```

- [ ] **Step 6: Style it**

Append to `Assets/css/timeinvoice.css`:

```css
.timeinvoice-unbilled { margin: 8px 0 14px; }
```

- [ ] **Step 7: Run tests to verify they pass**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/ --no-coverage --filter testUnbilledParticipants
```

Expected: PASS (4 tests). If `projectUserRoleModel->addUser()` has a different signature in this core version, adjust the test's setup — the assertion about `specific === false` is the contract that matters.

- [ ] **Step 8: Run the full suite**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeInvoice
```

Expected: OK, 87 tests.

- [ ] **Step 9: Commit**

```bash
git add Controller/InvoiceController.php Template/invoice/_banner.php Template/invoice/form.php Template/invoice/show.php Assets/css/timeinvoice.css Test/InvoiceControllerTest.php
git commit -m "feat: warn when an invoice is under-billing a team project

report() takes eight parameters; params 7-8 default to self-only, and
TimeInvoice calls it positionally with six. On any multi-person project the
invoice silently omits everyone else's hours. Until 1.3.0 ships the real
'Bill hours for' control, surface the gap read-only via participants()."
```

---

### Task 12: Version bump and documentation

**Files:**
- Modify: `plugin.json:4`, `Plugin.php` (`getPluginVersion`), `README.md`
- Test: `Test/PluginTest.php:13`

**Interfaces:**
- Consumes: nothing.
- Produces: version `1.2.0` in both required places.

- [ ] **Step 1: Write the failing test**

In `Test/PluginTest.php`, update the version assertion in `testMetadata()`:

```php
$this->assertSame('1.2.0', $p->getPluginVersion());
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/PluginTest.php --no-coverage
```

Expected: FAIL — `Failed asserting that '1.1.0' is identical to '1.2.0'`.

- [ ] **Step 3: Bump both version strings**

In `Plugin.php`:

```php
public function getPluginVersion(): string     { return '1.2.0'; }
```

In `plugin.json`:

```json
    "version": "1.2.0",
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeInvoice/Test/PluginTest.php --no-coverage
```

Expected: PASS — including `testVersionMatchesJson`, which pins the two in sync.

- [ ] **Step 5: Document the new controls**

In `README.md`, after the "Purpose" section, insert:

```markdown
## Using it

**Set your business details, default rate, currency, payment terms and invoice
number format** in *Settings → Invoices* (admin only).

**Set a project's rate, currency, payment terms and client** in the project
sidebar under *Invoice settings*. Invoices for that project inherit these; you
can override the rate, terms and client on an individual invoice.

**Create an invoice** from the project sidebar (*Invoices → New invoice*) or from
the top-level *Invoices* page, which has a project picker. As you set the date
range, grouping, rate and tax, the form shows live hours and totals.

**Look at an invoice** by clicking its number in the list. The invoice page shows
the line items and totals, and carries every action: download the PDF, view it
inline, edit, issue, delete, and mark paid.

**Issue an invoice** to freeze its snapshot and assign its number. TimeInvoice
does not email invoices — Kanboard's mail transport cannot carry a PDF
attachment. Download or view the PDF and send it however you normally do.

> **Multi-person projects:** this version bills only *your own* hours. Where
> other people have logged time in the range, the invoice form warns you. Billing
> several people, each at their own rate, arrives in 1.3.0.
```

- [ ] **Step 6: Run the full suite**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeInvoice
```

Expected: OK, 87 tests.

- [ ] **Step 7: Commit**

```bash
git add plugin.json Plugin.php README.md Test/PluginTest.php
git commit -m "chore: release 1.2.0 — navigation and visibility

Documents the new controls and bumps the version in both places CI checks."
```

---

## Done criteria

- [ ] Full suite green: `./testing/run-plugin-tests.sh TimeInvoice` → OK, 87 tests.
- [ ] `Settings → Invoices` is reachable from the admin sidebar without typing a URL.
- [ ] A project's sidebar offers both *Invoices* and *Invoice settings*.
- [ ] The top-level *Invoices* page offers a project picker and a create button.
- [ ] Clicking an invoice number opens a show page carrying every action.
- [ ] *View PDF* renders in the browser; *Download PDF* still downloads.
- [ ] The draft form shows live hours and totals as the rate changes.
- [ ] The word "Send" appears nowhere in the UI; the status reads *Issued*.
- [ ] A draft's payment terms override the project default.
- [ ] On a multi-person project, the under-billing banner appears.
- [ ] A `sent` invoice created under 1.1.0 still renders identically in the list, the show page and the PDF.

## Deliberately not in this release

Everything in the spec's **1.3.0 — the money model** table: per-user rates
(Q3, Q8), the "Bill hours for" control (Q7), two-level line items (Q10), the
fallback-rate relabel (Q12), the adaptive PDF (Q13) and freeze-time rate
resolution (Q14). Q11's banner exists specifically to generate evidence about
how often multi-person billing actually occurs before that complexity is
committed to.
