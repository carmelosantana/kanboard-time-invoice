<?php

namespace Kanboard\Plugin\TimeInvoice\Model;

/**
 * Pure line-item + totals math. No DB, no framework — unit-testable in isolation.
 * Line items come from TimeReport's breakdown[] (complete billable hours at the
 * chosen granularity), never detail[] (which only lists completed-in-range tasks).
 */
class InvoiceBuilder
{
    /**
     * @param  list<array{key:string,label:string,hours:float,task_count:int}> $breakdown
     * @return list<array{label:string,hours:float,amount:float}>
     */
    public static function lineItems(array $breakdown, float $rate): array
    {
        $items = [];
        foreach ($breakdown as $row) {
            $hours = (float) $row['hours'];
            $items[] = [
                'label'  => (string) $row['label'],
                'hours'  => $hours,
                'amount' => round($hours * $rate, 2),
            ];
        }
        return $items;
    }

    /**
     * @param  list<array{label:string,hours:float,amount:float}> $lineItems
     * @return array{subtotal:float,tax:float,total:float}
     */
    public static function totals(array $lineItems, bool $taxEnabled, float $taxRate): array
    {
        $subtotal = 0.0;
        foreach ($lineItems as $li) {
            $subtotal += (float) $li['amount'];
        }
        $subtotal = round($subtotal, 2);
        $tax = $taxEnabled ? round($subtotal * ($taxRate / 100), 2) : 0.0;
        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => round($subtotal + $tax, 2)];
    }
}
