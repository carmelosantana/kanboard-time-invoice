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
                'invoices' => [], 'outstanding' => 0.0, 'project' => null,
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
            'missing_dependency' => ! $this->hasTimeReport(),
            'currency'    => $this->globalDefaults()['currency'] ?? array('code' => 'USD', 'symbol' => '$'),
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
            'currency'    => ['code' => $v['currency_code'] ?? 'USD', 'symbol' => $v['currency_symbol'] ?? '$'],
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
     * Assemble the immutable snapshot frozen onto a sent invoice. Currency and
     * terms_days come from the layered defaults (global < project), NOT the
     * draft's hardcoded values, since the form does not expose those fields —
     * this makes a user's GLOBAL settings (e.g. GBP / Net-15) actually apply.
     */
    protected function freezeSnapshot(array $draft, int $userId): array
    {
        $projectId = (int) $draft['project_id'];
        $issueDate = $draft['issue_date'] ?? date('Y-m-d');
        $rate      = (float) ($draft['rate'] ?? 0);

        $global  = $this->globalDefaults();
        $project = $this->projectDefaults($projectId);
        $currency  = $project['currency'] ?? ($global['currency'] ?? ['code' => 'USD', 'symbol' => '$']);
        $termsDays = (int) ($project['terms_days'] ?? ($global['terms_days'] ?? 30));

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

        $this->response->withoutCache();
        $this->response->withContentType('application/pdf');
        $this->response->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"');
        $this->response->withBody($bytes);
        $this->response->send();
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
