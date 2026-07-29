<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF inventarizace rozvahových účtů (§29–30 ZoÚ) — REAL_data_followup_UX.md T2.
 *
 * Data = výstup BalanceInventoryService::build(): soupis účtů tříd 0–4 s KZ MD/D
 * a návrhem způsobu doložení, prázdné sloupce pro skutečný stav/rozdíl a hlavička
 * s datem inventarizace + prostor pro podpis odpovědné osoby.
 */
final class BalanceInventoryPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $title = (string) ($data['report_title'] ?? 'Inventarizace rozvahových účtů');
        $body = $this->renderTemplate('balance_inventory.twig', $data);
        $mpdf = $this->mpdf();
        $mpdf->SetTitle($title . ' ' . (string) ($data['period']['fiscal_year'] ?? ''));
        $this->withPageNumbers($mpdf, $title);
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
