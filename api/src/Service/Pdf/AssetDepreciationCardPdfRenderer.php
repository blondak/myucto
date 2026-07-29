<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF inventárních karet dlouhodobého majetku (§29–30 ZoÚ) — uzávěrkový
 * balíček #49, doplněk k {@see AssetInventoryPdfRenderer}.
 *
 * Data = výstup AssetDepreciationCardReportService::build() — jedna nebo víc karet
 * v `cards`; šablona dá každou další kartu na novou stránku (víc karet = víc stran,
 * jedna karta na majetek). ClosingPackageService volá renderer per karta (jedno
 * PDF na majetek, `Inventarizace-majetku/karty/karta-N.pdf`), ale renderer stejně
 * dobře zvládne i víc karet v jednom PDF.
 */
final class AssetDepreciationCardPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('asset_depreciation_card.twig', $data);
        $mpdf = $this->mpdf(['format' => 'A4', 'orientation' => 'P']);
        $first = $data['cards'][0] ?? null;
        $title = $first !== null
            ? 'Inventární karta majetku ' . (string) ($first['inventory_number'] ?? $first['card_number'])
            : 'Inventární karta dlouhodobého majetku';
        $mpdf->SetTitle($title);
        $this->withPageNumbers($mpdf, 'Inventární karta dlouhodobého majetku');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
