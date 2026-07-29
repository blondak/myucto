<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF dohody o vzájemném zápočtu (A4 portrait — Fáze F).
 *
 * Data = { agreement, entity, partner, receivables[], payables[] } sestavené
 * OffsetAction z OffsetService::build() + hlaviček firmy/partnera.
 */
final class OffsetAgreementPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('offset_agreement.twig', $data);
        $mpdf = $this->mpdf(['format' => 'A4', 'orientation' => 'P']);
        $mpdf->SetTitle('Dohoda o zápočtu ' . (string) ($data['agreement']['document_no'] ?? ''));
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
