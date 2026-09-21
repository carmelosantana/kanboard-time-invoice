<?php if (! empty($unbilled['visible'])): ?>
    <div class="alert alert-error timeinvoice-unbilled">
        <?php if (! empty($unbilled['specific'])): ?>
            <?= t('%d other people logged %s hours in this range that are not on this invoice.', (int) $unbilled['people'], number_format((float) $unbilled['hours'], 2)) ?>
        <?php else: ?>
            <?= t('You may not be seeing all billable hours on this project.') ?>
        <?php endif ?>
        <?= t('This invoice bills only your own hours.') ?>
    </div>
<?php endif ?>
