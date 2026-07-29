<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF skladového dokladu PRI/VYD/PRE (Epic SKLAD, vzor CashDocumentPdfRenderer).
 *
 * Data = assoc pole sestavené {@see \MyInvoice\Action\Stock\StockDocumentAction::pdf()}
 * z {@see \MyInvoice\Repository\StockDocumentRepository::findWithLines()} + hlaviček
 * skladu/dodavatele. A4 portrait.
 */
final class StockDocumentPdfRenderer extends ReportPdfRendererBase
{
    private const TYPE_LABELS = [
        'receipt'  => 'Příjemka',
        'issue'    => 'Výdejka',
        'transfer' => 'Převodka',
    ];

    private const TYPE_PREFIXES = [
        'receipt'  => 'PRI',
        'issue'    => 'VYD',
        'transfer' => 'PRE',
    ];

    public function render(array $data): string
    {
        $doc = $data['document'] ?? [];
        $docType = (string) ($doc['doc_type'] ?? '');
        $data['type_label']  = self::TYPE_LABELS[$docType] ?? 'Skladový doklad';
        $data['type_prefix'] = self::TYPE_PREFIXES[$docType] ?? 'SKL';

        $body = $this->renderTemplate('stock_document.twig', $data);
        $mpdf = $this->mpdf([
            'format'        => 'A4',
            'orientation'   => 'P',
            'margin_left'   => 14,
            'margin_right'  => 14,
            'margin_top'    => 14,
            'margin_bottom' => 14,
        ]);
        $mpdf->SetTitle($data['type_label'] . ' ' . (string) ($doc['doc_number'] ?? ''));
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
