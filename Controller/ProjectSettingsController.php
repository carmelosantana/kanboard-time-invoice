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
 * OR project manager on this specific project. A plain project member must not
 * be able to change what a client is billed.
 *
 * @package Kanboard\Plugin\TimeInvoice\Controller
 * @author  Carmelo Santana
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
        // getValues() is single-use/stateful — read it ONCE.
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
