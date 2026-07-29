<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF účetního deníku (A4 landscape — řádkový výpis s mnoha sloupci
 * MD/D/částka/popis, na šířku se vejde bez zalomení). Data = výstup
 * JournalExportService::build() (audit 2026-07). Číslování stran přes
 * {@see ReportPdfRendererBase::withPageNumbers()} (§13 ZoÚ — deník je zákonná kniha).
 */
final class JournalPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('journal.twig', $data);
        $mpdf = $this->mpdf(['format' => 'A4', 'orientation' => 'L']);
        $mpdf->SetTitle('Účetní deník');
        $this->withPageNumbers($mpdf, 'Účetní deník');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
