<?php

namespace Kanboard\Plugin\TimeInvoice\Model;

use Kanboard\Core\Base;

/**
 * Snapshot array -> PDF bytes via vendored FPDF (core Helvetica; cp1252 encoding
 * covers $, EUR, GBP). Isolated so a tFPDF/branded-font upgrade is a drop-in swap.
 */
class InvoicePdf extends Base
{
    public function render(array $s): string
    {
        require_once __DIR__ . '/../Assets/vendor/fpdf/fpdf.php';

        $sym = $s['currency']['symbol'] ?? '$';
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();

        // Header band
        $pdf->SetFont('Helvetica', 'B', 20);
        $pdf->Cell(0, 10, $this->enc($s['business']['name'] ?? ''), 0, 1);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Cell(0, 8, $this->enc('INVOICE ' . ($s['number'] ?? '(draft)')), 0, 1);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 6, $this->enc('Issued ' . ($s['issue_date'] ?? '') . '   Due ' . ($s['due_date'] ?? '')), 0, 1);
        $pdf->Ln(4);

        // Bill To
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(0, 6, $this->enc('Bill To'), 0, 1);
        $pdf->SetFont('Helvetica', '', 10);
        foreach (['name', 'address', 'email'] as $k) {
            if (! empty($s['client'][$k])) {
                $pdf->MultiCell(0, 5, $this->enc((string) $s['client'][$k]));
            }
        }
        $pdf->Ln(4);

        // Line-item table header
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(95, 7, $this->enc('Description'), 1);
        $pdf->Cell(25, 7, $this->enc('Hours'), 1, 0, 'R');
        $pdf->Cell(30, 7, $this->enc('Rate'), 1, 0, 'R');
        $pdf->Cell(30, 7, $this->enc('Amount'), 1, 1, 'R');

        $pdf->SetFont('Helvetica', '', 10);
        $rate = $this->rateFromSnapshot($s);
        foreach ($s['line_items'] ?? [] as $li) {
            $pdf->Cell(95, 7, $this->enc((string) $li['label']), 1);
            $pdf->Cell(25, 7, number_format((float) $li['hours'], 2), 1, 0, 'R');
            $pdf->Cell(30, 7, $sym . number_format($rate, 2), 1, 0, 'R');
            $pdf->Cell(30, 7, $sym . number_format((float) $li['amount'], 2), 1, 1, 'R');
        }

        // Totals
        $this->totalRow($pdf, $sym, 'Subtotal', (float) ($s['subtotal'] ?? 0));
        if (! empty($s['tax']['enabled'])) {
            $this->totalRow($pdf, $sym, 'Tax (' . rtrim(rtrim(number_format((float) $s['tax']['rate'], 3), '0'), '.') . '%)', (float) $s['tax']['amount']);
        }
        $pdf->SetFont('Helvetica', 'B', 11);
        $this->totalRow($pdf, $sym, 'Total', (float) ($s['total'] ?? 0), true);

        // Notes
        if (! empty($s['notes'])) {
            $pdf->Ln(6);
            $pdf->SetFont('Helvetica', 'I', 9);
            $pdf->MultiCell(0, 5, $this->enc((string) $s['notes']));
        }

        // DRAFT watermark
        if (($s['status'] ?? '') === 'draft') {
            $pdf->SetFont('Helvetica', 'B', 60);
            $pdf->SetTextColor(220, 220, 220);
            $pdf->SetXY(35, 120);
            $pdf->Cell(0, 20, 'DRAFT');
            $pdf->SetTextColor(0, 0, 0);
        }

        return $pdf->Output('S');
    }

    private function totalRow(\FPDF $pdf, string $sym, string $label, float $amount, bool $bold = false): void
    {
        $pdf->Cell(120, 7, '', 0);
        $pdf->Cell(30, 7, $this->enc($label), $bold ? 1 : 0, 0, 'R');
        $pdf->Cell(30, 7, $sym . number_format($amount, 2), 1, 1, 'R');
    }

    private function rateFromSnapshot(array $s): float
    {
        if (isset($s['rate'])) {
            return (float) $s['rate'];
        }
        $items = $s['line_items'] ?? [];
        if ($items && (float) $items[0]['hours'] > 0) {
            return round((float) $items[0]['amount'] / (float) $items[0]['hours'], 2);
        }
        return 0.0;
    }

    private function enc(string $s): string
    {
        $out = iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
        return $out === false ? $s : $out;
    }
}
