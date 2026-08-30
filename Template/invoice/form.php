<div class="page-header"><h2><?= t('Invoice for %s', $project['name']) ?></h2></div>

<form method="post" action="<?= $this->url->href('InvoiceController', 'saveDraft', array('plugin' => 'TimeInvoice')) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>
    <?= $this->form->hidden('project_id', $values) ?>
    <?= $this->form->hidden('id', $values) ?>

    <?= $this->form->label(t('Start date'), 'start_date') ?>
    <?= $this->form->text('start_date', $values) ?>
    <?= $this->form->label(t('End date'), 'end_date') ?>
    <?= $this->form->text('end_date', $values) ?>

    <?= $this->form->label(t('Group line items by'), 'granularity') ?>
    <?= $this->form->select('granularity', array('task' => t('Task'), 'day' => t('Day'), 'week' => t('Week'), 'total' => t('Single total')), $values) ?>

    <?= $this->form->label(t('Hourly rate'), 'rate') ?>
    <?= $this->form->number('rate', $values, array(), array('step' => '0.01')) ?>

    <?= $this->form->checkbox('tax_enabled', t('Apply tax'), '1', ! empty($values['tax_enabled'])) ?>
    <?= $this->form->label(t('Tax rate %%'), 'tax_rate') ?>
    <?= $this->form->number('tax_rate', $values, array(), array('step' => '0.001')) ?>

    <?= $this->form->label(t('Client name'), 'client_name') ?>
    <?= $this->form->text('client_name', array('client_name' => $values['client']['name'] ?? '')) ?>
    <?= $this->form->label(t('Client address'), 'client_address') ?>
    <?= $this->form->textarea('client_address', array('client_address' => $values['client']['address'] ?? '')) ?>
    <?= $this->form->label(t('Client email'), 'client_email') ?>
    <?= $this->form->text('client_email', array('client_email' => $values['client']['email'] ?? '')) ?>

    <?= $this->form->label(t('Notes / terms'), 'notes') ?>
    <?= $this->form->textarea('notes', $values) ?>

    <?php if (! empty($ai_ready)): ?>
        <div class="timeinvoice-ai">
            <?= $this->form->label(t('AI provider'), 'profile_id') ?>
            <select class="timeinvoice-profile" name="profile_id">
                <?php foreach ($ai_profiles as $p): ?>
                    <option value="<?= $this->text->e($p['id']) ?>"<?= $p['id'] === $ai_default_profile ? ' selected' : '' ?>><?= $this->text->e($p['label']) ?></option>
                <?php endforeach ?>
            </select>
            <button type="button" class="btn timeinvoice-generate-note"
                    data-url="<?= $this->url->href('InvoiceController', 'generateCoverNote', array('plugin' => 'TimeInvoice')) ?>">
                <?= t('Generate cover note') ?>
            </button>
        </div>
    <?php endif ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save draft') ?></button>
    </div>
</form>
