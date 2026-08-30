<div class="page-header"><h2><?= t('Invoice settings') ?></h2></div>

<form method="post" action="<?= $this->url->href('SettingsController', 'save', array('plugin' => 'TimeInvoice')) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>

    <?= $this->form->label(t('Business name'), 'business_name') ?>
    <?= $this->form->text('business_name', array('business_name' => $values['business']['name'] ?? '')) ?>
    <?= $this->form->label(t('Business address'), 'business_address') ?>
    <?= $this->form->textarea('business_address', array('business_address' => $values['business']['address'] ?? '')) ?>
    <?= $this->form->label(t('Business email'), 'business_email') ?>
    <?= $this->form->text('business_email', array('business_email' => $values['business']['email'] ?? '')) ?>

    <?= $this->form->label(t('Currency code'), 'currency_code') ?>
    <?= $this->form->text('currency_code', array('currency_code' => $values['currency']['code'] ?? 'USD')) ?>
    <?= $this->form->label(t('Currency symbol'), 'currency_symbol') ?>
    <?= $this->form->text('currency_symbol', array('currency_symbol' => $values['currency']['symbol'] ?? '$')) ?>

    <?= $this->form->label(t('Default hourly rate'), 'rate') ?>
    <?= $this->form->number('rate', $values, array(), array('step' => '0.01')) ?>

    <?= $this->form->checkbox('tax_enabled', t('Apply tax by default'), '1', ! empty($values['tax_enabled'])) ?>
    <?= $this->form->label(t('Default tax rate %%'), 'tax_rate') ?>
    <?= $this->form->number('tax_rate', $values, array(), array('step' => '0.001')) ?>

    <?= $this->form->label(t('Payment terms (days)'), 'terms_days') ?>
    <?= $this->form->number('terms_days', $values) ?>
    <?= $this->form->label(t('Default notes / terms'), 'terms') ?>
    <?= $this->form->textarea('terms', $values) ?>

    <?= $this->form->label(t('Invoice number format'), 'number_format') ?>
    <?= $this->form->text('number_format', $values) ?>
    <p class="form-help"><?= t('Tokens: {YYYY} = year, {seq} = per-year sequence (zero-padded).') ?></p>

    <?= $this->form->label(t('AI cover-note style instructions'), 'ai_style') ?>
    <?= $this->form->textarea('ai_style', $values) ?>
    <p class="form-help"><?= t('Guides the AI cover note tone (e.g. formal, concise, first-person plural). Leave blank for a sensible default.') ?></p>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save settings') ?></button>
    </div>
</form>
