<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF inventarizace dlouhodobého majetku (§29–30 ZoÚ) — uzávěrkový balíček #33.
 *
 * Data = výstup AssetInventoryReportService::build(): soupis karet majetku existujících
 * k rozvahovému dni s pořizovací cenou, oprávkami a zůstatkovou cenou.
 */
final class AssetInventoryPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('asset_inventory.twig', $data);
        $mpdf = $this->mpdf();
        $mpdf->SetTitle('Inventarizace dlouhodobého majetku ' . (string) ($data['period']['fiscal_year'] ?? ''));
        $this->withPageNumbers($mpdf, 'Inventarizace dlouhodobého majetku');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
