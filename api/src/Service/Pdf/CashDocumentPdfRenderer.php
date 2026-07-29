<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF pokladního dokladu PPD/VPD (mini-epic POKLADNA #14, §5.5/O9).
 *
 * Data = výstup {@see \MyInvoice\Service\Accounting\Cash\CashDocumentService::pdfData()}.
 * Náležitosti dle §11 ZoÚ + §30 ZDPH (označení a číslo dokladu, firma, účastník,
 * datum vystavení + DUZP, částka číslem, účel, DPH rekapitulace u daňových dokladů,
 * podpisové bloky, patička s kontací MD/D). BEZ „Kč slovy" (E3 — §11 ZoÚ nevyžaduje).
 * A4 portrait.
 */
final class CashDocumentPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('cash_document.twig', $data);
        $mpdf = $this->mpdf([
            'format'      => 'A4',
            'orientation' => 'P',
            'margin_left'   => 14,
            'margin_right'  => 14,
            'margin_top'    => 14,
            'margin_bottom' => 14,
        ]);
        $doc = $data['document'] ?? [];
        $label = ($doc['doc_type'] ?? 'in') === 'in' ? 'Příjmový pokladní doklad' : 'Výdajový pokladní doklad';
        $mpdf->SetTitle($label . ' ' . (string) ($doc['doc_number'] ?? ''));
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
