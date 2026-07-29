<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

final class StockTakePdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('stock_take.twig', $data);
        $mpdf = $this->mpdf([
            'format' => 'A4',
            'orientation' => 'L',
            'margin_left' => 10,
            'margin_right' => 10,
        ]);
        $mpdf->SetTitle('Inventurní soupis #' . (string) ($data['take']['id'] ?? ''));
        $this->withPageNumbers($mpdf, 'Inventurní soupis');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
