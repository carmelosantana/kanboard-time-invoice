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
}
