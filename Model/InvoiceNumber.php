<?php

namespace Kanboard\Plugin\TimeInvoice\Model;

/** Pure numbering: gap-free, resets per calendar year of the issue date. */
class InvoiceNumber
{
    /**
     * @param  array<string,int> $counter  year => last sequence used
     * @return array{number:string,counter:array<string,int>}
     */
    public static function next(array $counter, string $issueDate, string $format): array
    {
        $year = substr($issueDate, 0, 4);
        $seq  = (int) ($counter[$year] ?? 0) + 1;
        $counter[$year] = $seq;

        $number = str_replace(
            ['{YYYY}', '{seq}'],
            [$year, str_pad((string) $seq, 3, '0', STR_PAD_LEFT)],
            $format
        );
        return ['number' => $number, 'counter' => $counter];
    }
}
