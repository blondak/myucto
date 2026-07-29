<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF „Placení záloh dle §38a zákona č. 586/1992 Sb.". Data =
 * {@see \MyInvoice\Service\Report\IncomeTaxAdvanceNoticeReportService::build()}.
 */
final class IncomeTaxAdvanceNoticePdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('income_tax_advance_notice.twig', ['data' => $data]);
        $mpdf = $this->mpdf([
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 14,
        ]);
        $mpdf->SetTitle('Placení záloh dle §38a — ' . (string) ($data['year'] ?? ''));
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
