<?php

namespace Kanboard\Plugin\TimeInvoice\Controller;

use Kanboard\Controller\BaseController;

class SettingsController extends BaseController
{
    protected function currentSettings(): array
    {
        $cfg = $this->configModel;
        return [
            'business'      => json_decode($cfg->get('timeinvoice_business', '{}'), true) ?: [],
            'currency'      => json_decode($cfg->get('timeinvoice_currency', '{"code":"USD","symbol":"$"}'), true) ?: ['code' => 'USD', 'symbol' => '$'],
            'rate'          => (float) $cfg->get('timeinvoice_rate', '0'),
            'tax_enabled'   => $cfg->get('timeinvoice_tax_enabled', '0') === '1',
            'tax_rate'      => (float) $cfg->get('timeinvoice_tax_rate', '0'),
            'terms'         => $cfg->get('timeinvoice_terms', ''),
            'terms_days'    => (int) $cfg->get('timeinvoice_terms_days', '30'),
            'number_format' => $cfg->get('timeinvoice_number_format', 'INV-{YYYY}-{seq}') ?: 'INV-{YYYY}-{seq}',
        ];
    }

    public function show(): void
    {
        $this->response->html($this->helper->layout->app('TimeInvoice:invoice/settings', [
            'title'  => t('Invoice settings'),
            'values' => $this->currentSettings(),
        ]));
    }

    public function save(): void
    {
        $this->checkCSRFForm();
        $v = $this->request->getValues();
        $this->configModel->save([
            'timeinvoice_business'      => json_encode(['name' => $v['business_name'] ?? '', 'address' => $v['business_address'] ?? '', 'email' => $v['business_email'] ?? '']),
            'timeinvoice_currency'      => json_encode(['code' => $v['currency_code'] ?? 'USD', 'symbol' => $v['currency_symbol'] ?? '$']),
            'timeinvoice_rate'          => (string) (float) ($v['rate'] ?? 0),
            'timeinvoice_tax_enabled'   => empty($v['tax_enabled']) ? '0' : '1',
            'timeinvoice_tax_rate'      => (string) (float) ($v['tax_rate'] ?? 0),
            'timeinvoice_terms'         => (string) ($v['terms'] ?? ''),
            'timeinvoice_terms_days'    => (string) (int) ($v['terms_days'] ?? 30),
            'timeinvoice_number_format' => (string) ($v['number_format'] ?? 'INV-{YYYY}-{seq}'),
        ]);
        $this->response->redirect($this->helper->url->to('SettingsController', 'show', ['plugin' => 'TimeInvoice']));
    }
}
