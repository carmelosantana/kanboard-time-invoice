<div class="page-header"><h2><?= t('Invoices') ?></h2></div>

<?php if (! empty($missing_dependency)): ?>
    <div class="alert alert-error"><?= t('TimeInvoice requires the TimeReport plugin, which is not enabled.') ?></div>
<?php else: ?>
    <?php if ($project): ?>
        <p><?= $this->url->link(t('New invoice'), 'InvoiceController', 'form', array('plugin' => 'TimeInvoice', 'project_id' => $project['id']), false, 'btn btn-blue') ?></p>
    <?php endif ?>

    <p class="timeinvoice-outstanding"><?= t('Outstanding') ?>: <?= $this->helper->invoice->money((float) $outstanding, array('symbol' => '$')) ?></p>

    <?php if (empty($invoices)): ?>
        <p class="alert"><?= t('No invoices yet.') ?></p>
    <?php else: ?>
        <table class="table-striped">
            <tr>
                <th><?= t('Number') ?></th><th><?= t('Status') ?></th>
                <th><?= t('Issued') ?></th><th><?= t('Total') ?></th><th><?= t('Actions') ?></th>
            </tr>
            <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td><?= $this->text->e($inv['number'] ?? t('(draft)')) ?></td>
                    <td class="<?= $this->helper->invoice->statusClass($inv['status'] ?? '') ?>"><?= $this->helper->invoice->statusLabel($inv['status'] ?? '') ?></td>
                    <td><?= $this->text->e($inv['issue_date'] ?? $inv['created_at'] ?? '') ?></td>
                    <td><?= $this->helper->invoice->money((float) ($inv['total'] ?? 0), $inv['currency'] ?? array('symbol' => '$')) ?></td>
                    <td>
                        <?= $this->url->link(t('PDF'), 'InvoiceController', 'pdf', array('plugin' => 'TimeInvoice', 'project_id' => $inv['project_id'], 'id' => $inv['id'])) ?>
                        <?php if (($inv['status'] ?? '') === 'draft'): ?>
                            | <?= $this->url->link(t('Edit'), 'InvoiceController', 'form', array('plugin' => 'TimeInvoice', 'project_id' => $inv['project_id'], 'id' => $inv['id'])) ?>
                            | <?= $this->url->link(t('Send'), 'InvoiceController', 'send', array('plugin' => 'TimeInvoice', 'project_id' => $inv['project_id'], 'id' => $inv['id']), false, 'timeinvoice-send') ?>
                            | <?= $this->url->link(t('Delete'), 'InvoiceController', 'delete', array('plugin' => 'TimeInvoice', 'project_id' => $inv['project_id'], 'id' => $inv['id']), false, 'timeinvoice-delete') ?>
                        <?php elseif (($inv['status'] ?? '') === 'sent'): ?>
                            | <?= $this->url->link(t('Mark paid'), 'InvoiceController', 'markPaid', array('plugin' => 'TimeInvoice', 'project_id' => $inv['project_id'], 'id' => $inv['id'])) ?>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
        </table>
    <?php endif ?>
<?php endif ?>
