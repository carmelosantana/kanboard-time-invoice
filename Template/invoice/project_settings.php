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
