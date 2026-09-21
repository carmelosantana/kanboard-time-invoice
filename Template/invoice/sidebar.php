<li>
    <?= $this->url->link(t('Invoices'), 'InvoiceController', 'project', array('plugin' => 'TimeInvoice', 'project_id' => $project['id'])) ?>
</li>
<li>
    <?= $this->url->link(t('Invoice settings'), 'ProjectSettingsController', 'show', array('plugin' => 'TimeInvoice', 'project_id' => $project['id'])) ?>
</li>
