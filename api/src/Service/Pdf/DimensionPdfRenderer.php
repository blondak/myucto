<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

final class DimensionPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('dimension_profit.twig', $data);
        $pdf = $this->mpdf();
        $pdf->SetTitle('Výsledovka po dimenzi');
        $this->withPageNumbers($pdf, 'Výsledovka po dimenzi');
        ChunkedHtmlWriter::write($pdf, $body);
        return $pdf->Output('', 'S');
    }

    public function renderTable(array $table): string
    {
        $body = $this->renderTemplate('dimension_table.twig', $table);
        $pdf = $this->mpdf();
        $pdf->SetTitle($table['title']);
        $this->withPageNumbers($pdf, $table['title']);
        ChunkedHtmlWriter::write($pdf, $body);
        return $pdf->Output('', 'S');
    }
}
