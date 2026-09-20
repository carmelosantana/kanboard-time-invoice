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
