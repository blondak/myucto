<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF soupisu drobného majetku k datu (A4 landscape).
 *
 * Data = výstup SmallAssetReportService::inventory() (§DM Sestavy 1). Tohle je ta
 * sestava, kterou účetní podepisuje k inventarizaci (§28/5 ZoÚ, §29–30 ZoÚ), proto
 * číslování stran — bez něj nejde doložit úplnost soupisu.
 */
final class SmallAssetInventoryPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('small_asset_inventory.twig', $data);
        $mpdf = $this->mpdf();
        $mpdf->SetTitle('Soupis drobného majetku k ' . (string) ($data['as_of'] ?? ''));
        $this->withPageNumbers($mpdf, 'Soupis drobného majetku');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
