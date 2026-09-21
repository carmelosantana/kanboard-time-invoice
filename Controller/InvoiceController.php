<?php

namespace Kanboard\Plugin\TimeInvoice\Controller;

use Kanboard\Controller\BaseController;

class InvoiceController extends BaseController
{
    /** Defensive dependency gate — TimeReport supplies the hours source. */
    protected function hasTimeReport(): bool
    {
        return isset($this->container['timeReportModel']);
    }

    protected function accessibleProjectIds(int $userId): array
    {
        return array_map('intval', $this->projectPermissionModel->getActiveProjectIds($userId));
    }

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

    protected function aiRegistry(): ?object
    {
        $cls = '\\Kanboard\\Plugin\\AiConnector\\Model\\ProviderRegistry';
        return class_exists($cls) ? new $cls($this->container) : null;
    }

    protected function aiCatalog(): ?object
    {
        $cls = '\\Kanboard\\Plugin\\AiConnector\\Model\\ModelCatalog';
        return class_exists($cls) ? new $cls($this->container) : null;
    }

    /** Both AiConnector classes must exist (1.1.0+) AND a provider must be usable. */
    protected function aiReady(): bool
    {
        $reg = $this->aiRegistry();
        return $reg !== null && $this->aiCatalog() !== null && $reg->isReady();
    }

    protected function aiProfiles(): array
    {
        $reg = $this->aiRegistry();
        return $reg !== null ? $reg->listProfiles() : [];
    }

    protected function aiDefaultProfile(): string
    {
        $reg = $this->aiRegistry();
        return $reg !== null ? (string) $reg->getDefaultProfileId() : '';
    }

    /** Cross-project invoice list + outstanding total. */
    public function list(): void
    {
        if (! $this->hasTimeReport()) {
            $this->response->html($this->helper->layout->app('TimeInvoice:invoice/list', [
                'title' => t('Invoices'), 'missing_dependency' => true,
                'invoices' => [], 'outstanding' => 0.0, 'project' => null, 'projects' => [],
                'currency' => array('code' => 'USD', 'symbol' => '$'),
            ]));
            return;
        }
        $userId = $this->userSession->getId();
        $pids = $this->accessibleProjectIds($userId);
        $this->response->html($this->helper->layout->app('TimeInvoice:invoice/list', [
            'title'       => t('Invoices'),
            'invoices'    => $this->invoiceModel->listAll($pids),
            'outstanding' => $this->invoiceModel->outstandingTotal($pids),
            'project'     => null,
            'projects'    => $this->accessibleProjects($userId),
            'missing_dependency' => false,
            'currency'    => $this->globalDefaults()['currency'] ?? array('code' => 'USD', 'symbol' => '$'),
        ]));
    }

    /** Per-project invoice list. */
    public function project(): void
    {
        $userId = $this->userSession->getId();
        $projectId = $this->request->getIntegerParam('project_id');
        if (! in_array($projectId, $this->accessibleProjectIds($userId), true)) {
            $this->response->redirect($this->helper->url->to('InvoiceController', 'list', ['plugin' => 'TimeInvoice']));
            return;
        }
        $this->response->html($this->helper->layout->app('TimeInvoice:invoice/list', [
            'title'       => t('Invoices'),
            'invoices'    => $this->invoiceModel->listByProject($projectId),
            'outstanding' => $this->invoiceModel->outstandingTotal([$projectId]),
            'project'     => $this->projectModel->getById($projectId),
            'projects'    => [],
            'missing_dependency' => ! $this->hasTimeReport(),
            'currency'    => $this->globalDefaults()['currency'] ?? array('code' => 'USD', 'symbol' => '$'),
        ]));
    }

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
            'unbilled'   => $this->unbilledParticipants(
                $projectId,
                (string) ($snap['range']['start'] ?? date('Y-m-01')),
                (string) ($snap['range']['end'] ?? date('Y-m-d')),
                $userId
            ),
        ]));
    }

    protected function globalDefaults(): array
    {
        $cfg = $this->configModel;
        return [
            'business'    => json_decode($cfg->get('timeinvoice_business', '{}'), true) ?: [],
            'currency'    => json_decode($cfg->get('timeinvoice_currency', '{"code":"USD","symbol":"$"}'), true) ?: ['code' => 'USD', 'symbol' => '$'],
            'rate'        => (float) $cfg->get('timeinvoice_rate', '0'),
            'tax_enabled' => $cfg->get('timeinvoice_tax_enabled', '0') === '1',
            'tax_rate'    => (float) $cfg->get('timeinvoice_tax_rate', '0'),
            'terms'       => $cfg->get('timeinvoice_terms', ''),
            'terms_days'  => (int) $cfg->get('timeinvoice_terms_days', '30'),
        ];
    }

    protected function projectDefaults(int $projectId): array
    {
        $raw = $this->projectMetadataModel->get($projectId, 'timeinvoice:defaults', '{}');
        return json_decode($raw ?: '{}', true) ?: [];
    }

    public function form(): void
    {
        $userId = $this->userSession->getId();
        $projectId = $this->request->getIntegerParam('project_id');
        if (! in_array($projectId, $this->accessibleProjectIds($userId), true)) {
            $this->response->redirect($this->helper->url->to('InvoiceController', 'list', ['plugin' => 'TimeInvoice']));
            return;
        }

        $id = $this->request->getStringParam('id');
        $existing = $id !== '' ? $this->invoiceModel->load($projectId, $id) : null;
        $defaults = \Kanboard\Plugin\TimeInvoice\Model\DefaultsResolver::resolve(
            $this->globalDefaults(),
            $this->projectDefaults($projectId),
            $existing ?? []
        );

        $values = array_merge([
            'project_id'  => $projectId,
            'id'          => $id,
            'start_date'  => $existing['range']['start'] ?? date('Y-m-01'),
            'end_date'    => $existing['range']['end'] ?? date('Y-m-d'),
            'granularity' => $existing['granularity'] ?? 'task',
        ], $defaults);

        $this->response->html($this->helper->layout->app('TimeInvoice:invoice/form', [
            'title'              => t('New invoice'),
            'project'            => $this->projectModel->getById($projectId),
            'values'             => $values,
            'ai_ready'           => $this->aiReady(),
            'ai_profiles'        => $this->aiProfiles(),
            'ai_default_profile' => $this->aiDefaultProfile(),
            'unbilled'           => $this->unbilledParticipants(
                $projectId,
                (string) $values['start_date'],
                (string) $values['end_date'],
                $userId
            ),
        ]));
    }

    protected function buildDraftFromRequest(array $v): array
    {
        return [
            'range'       => ['start' => $this->validDate($v['start_date'] ?? '', date('Y-m-01')),
                              'end'   => $this->validDate($v['end_date'] ?? '', date('Y-m-d'))],
            'granularity' => in_array($v['granularity'] ?? '', ['day', 'week', 'task', 'total'], true) ? $v['granularity'] : 'task',
            'rate'        => (float) ($v['rate'] ?? 0),
            'tax_enabled' => ! empty($v['tax_enabled']),
            'tax_rate'    => (float) ($v['tax_rate'] ?? 0),
            'client'      => ['name' => $v['client_name'] ?? '', 'address' => $v['client_address'] ?? '', 'email' => $v['client_email'] ?? ''],
            'terms'       => (string) ($v['terms'] ?? ''),
            'terms_days'  => (int) ($v['terms_days'] ?? 30),
            'notes'       => (string) ($v['notes'] ?? ''),
        ];
    }

    public function saveDraft(): void
    {
        $this->checkCSRFForm();
        $userId = $this->userSession->getId();
        $v = $this->request->getValues();
        $projectId = (int) ($v['project_id'] ?? 0);
        if (! in_array($projectId, $this->accessibleProjectIds($userId), true)) {
            $this->response->redirect($this->helper->url->to('InvoiceController', 'list', ['plugin' => 'TimeInvoice']));
            return;
        }

        $draft = $this->buildDraftFromRequest($v);
        $id = (string) ($v['id'] ?? '');
        if ($id !== '' && $this->invoiceModel->load($projectId, $id)) {
            $existing = $this->invoiceModel->load($projectId, $id);
            $this->invoiceModel->createDraft($projectId, $userId, array_merge($draft, ['id' => $existing['id']]));
        } else {
            $this->invoiceModel->createDraft($projectId, $userId, $draft);
        }
        $this->response->redirect($this->helper->url->to('InvoiceController', 'project', ['plugin' => 'TimeInvoice', 'project_id' => $projectId]));
    }

    private function validDate(string $value, string $fallback): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $fallback;
    }

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

        $report = $this->timeReportModel->report(
            $projectId,
            $draft['range']['start'], $draft['range']['end'],
            $draft['granularity'] ?? 'task', true, $userId
        );
        $items  = \Kanboard\Plugin\TimeInvoice\Model\InvoiceBuilder::lineItems($report['breakdown'] ?? [], $rate);
        $totals = \Kanboard\Plugin\TimeInvoice\Model\InvoiceBuilder::totals($items, ! empty($draft['tax_enabled']), (float) ($draft['tax_rate'] ?? 0));

        return [
            'issue_date' => $issueDate,
            'due_date'   => date('Y-m-d', strtotime($issueDate . ' +' . $termsDays . ' days')),
            'range'      => $draft['range'],
            'granularity'=> $draft['granularity'] ?? 'task',
            'currency'   => $currency,
            'business'   => $global['business'] ?? [],
            'client'     => $draft['client'] ?? [],
            'rate'       => $rate,
            'line_items' => $items,
            'subtotal'   => $totals['subtotal'],
            'tax'        => ['enabled' => ! empty($draft['tax_enabled']), 'rate' => (float) ($draft['tax_rate'] ?? 0), 'amount' => $totals['tax']],
            'total'      => $totals['total'],
            'notes'      => (string) (! empty($draft['notes']) ? $draft['notes'] : ($global['terms'] ?? '')),
        ];
    }

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

    public function send(): void
    {
        $this->checkCSRFParam();
        $userId = $this->userSession->getId();
        $projectId = $this->request->getIntegerParam('project_id');
        $id = $this->request->getStringParam('id');
        if (! in_array($projectId, $this->accessibleProjectIds($userId), true) || ! $this->hasTimeReport()) {
            $this->response->redirect($this->helper->url->to('InvoiceController', 'list', ['plugin' => 'TimeInvoice']));
            return;
        }
        $draft = $this->invoiceModel->load($projectId, $id);
        if ($draft !== null && ($draft['status'] ?? '') === 'draft') {
            $draft['issue_date'] = date('Y-m-d');
            $snapshot = $this->freezeSnapshot($draft, $userId);
            $this->invoiceModel->send($projectId, $id, $snapshot);
        }
        $this->response->redirect($this->helper->url->to('InvoiceController', 'project', ['plugin' => 'TimeInvoice', 'project_id' => $projectId]));
    }

    public function markPaid(): void
    {
        $this->checkCSRFParam();
        $userId = $this->userSession->getId();
        $projectId = $this->request->getIntegerParam('project_id');
        if (in_array($projectId, $this->accessibleProjectIds($userId), true)) {
            $this->invoiceModel->markPaid($projectId, $this->request->getStringParam('id'));
        }
        $this->response->redirect($this->helper->url->to('InvoiceController', 'project', ['plugin' => 'TimeInvoice', 'project_id' => $projectId]));
    }

    /** Draft -> live preview snapshot (status draft); sent/paid -> stored frozen snapshot. */
    protected function snapshotForPdf(int $projectId, string $id, int $userId): array
    {
        $rec = $this->invoiceModel->load($projectId, $id);
        if ($rec === null) {
            return [];
        }
        if (($rec['status'] ?? '') === 'draft') {
            $rec['issue_date'] = date('Y-m-d');
            $snap = $this->freezeSnapshot($rec, $userId);
            $snap['status'] = 'draft';
            $snap['number'] = null;
            return $snap;
        }
        return $rec;
    }

    /**
     * Content-Disposition for the PDF response. `inline` lets the browser's own
     * viewer render the invoice instead of forcing a download — the show page's
     * "View PDF" action. Pure seam so the choice is unit-testable without HTTP.
     */
    protected function contentDisposition(bool $inline, string $name): string
    {
        return ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"';
    }

    public function pdf(): void
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
        $bytes = $this->invoicePdf->render($snap);
        $name = ($snap['number'] ?? 'draft') . '.pdf';

        $inline = $this->request->getIntegerParam('inline') === 1;

        $this->response->withoutCache();
        $this->response->withContentType('application/pdf');
        $this->response->withHeader('Content-Disposition', $this->contentDisposition($inline, $name));
        $this->response->withBody($bytes);
        $this->response->send();
    }

    /** Map request values → the CoverNoteGenerator context. Pure enough to unit-test. */
    protected function coverNoteContext(array $v, int $projectId, int $userId): array
    {
        return [
            'project_id'   => $projectId,
            'user_id'      => $userId,
            'start'        => $this->validDate($v['start_date'] ?? '', date('Y-m-01')),
            'end'          => $this->validDate($v['end_date'] ?? '', date('Y-m-d')),
            'granularity'  => in_array($v['granularity'] ?? '', ['day', 'week', 'task', 'total'], true) ? $v['granularity'] : 'task',
            'rate'         => (float) ($v['rate'] ?? 0),
            'tax_enabled'  => ! empty($v['tax_enabled']),
            'tax_rate'     => (float) ($v['tax_rate'] ?? 0),
            'project_name' => (string) ($this->projectModel->getById($projectId)['name'] ?? ''),
            'client'       => ['name' => (string) ($v['client_name'] ?? '')],
            'currency'     => $this->globalDefaults()['currency'] ?? ['code' => 'USD', 'symbol' => '$'],
            'style'        => (string) $this->configModel->get('timeinvoice_ai_style', ''),
            'profile_id'   => ($v['profile_id'] ?? '') !== '' ? (string) $v['profile_id'] : null,
        ];
    }

    /**
     * Resolve project_id from the POST body ($_POST), not the query string.
     * The invoice form AJAXes it as a hidden field via $form.serialize(), so
     * getIntegerParam() ($_GET) would see 0 — read it from getValues() instead.
     */
    protected function requestProjectId(array $values): int
    {
        return (int) ($values['project_id'] ?? 0);
    }

    /** POST — generate an AI cover note as JSON for the draft form. */
    public function generateCoverNote(): void
    {
        $this->checkCSRFForm();
        $userId = $this->userSession->getId();
        $values = $this->request->getValues();
        $projectId = $this->requestProjectId($values);

        if (! in_array($projectId, $this->accessibleProjectIds($userId), true) || ! $this->hasTimeReport()) {
            $this->response->json(['error' => t('Not available for this project.')], 400);
            return;
        }
        if (! $this->aiReady()) {
            $this->response->json(['error' => t('AI cover notes need the AiConnector plugin (1.1.0+) with a configured provider.')], 400);
            return;
        }

        $ctx  = $this->coverNoteContext($values, $projectId, $userId);
        $note = $this->coverNoteGenerator->generate($ctx, $this->aiRegistry(), $this->aiCatalog());

        if ($note === null) {
            $this->response->json(['error' => t('The model returned no cover note. Try again or pick a different provider.')], 502);
            return;
        }
        $this->response->json(['note' => $note]);
    }

    public function delete(): void
    {
        $this->checkCSRFParam();
        $userId = $this->userSession->getId();
        $projectId = $this->request->getIntegerParam('project_id');
        if (in_array($projectId, $this->accessibleProjectIds($userId), true)) {
            $this->invoiceModel->delete($projectId, $this->request->getStringParam('id'));
        }
        $this->response->redirect($this->helper->url->to('InvoiceController', 'project', ['plugin' => 'TimeInvoice', 'project_id' => $projectId]));
    }
}
