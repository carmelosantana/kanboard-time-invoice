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

    /** Cross-project invoice list + outstanding total. */
    public function list(): void
    {
        if (! $this->hasTimeReport()) {
            $this->response->html($this->helper->layout->app('TimeInvoice:invoice/list', [
                'title' => t('Invoices'), 'missing_dependency' => true,
                'invoices' => [], 'outstanding' => 0.0, 'project' => null,
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
            'title'   => t('New invoice'),
            'project' => $this->projectModel->getById($projectId),
            'values'  => $values,
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
}
