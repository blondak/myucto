<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF objednávky vydané dodavateli (Epic SKLAD „na cestě", fáze 1;
 * vzor {@see StockDocumentPdfRenderer}).
 *
 * Data sestavuje {@see \MyInvoice\Action\Stock\PurchaseOrderAction::pdf()}.
 * Objednávka NENÍ daňový doklad — PDF proto neuvádí DPH jako nárokovatelnou
 * daň, jen orientační celkovou cenu, na které se strany domluvily. A4 portrait.
 */
final class PurchaseOrderPdfRenderer extends ReportPdfRendererBase
{
    private const STATE_LABELS = [
        'draft'              => 'Rozpracovaná',
        'sent'               => 'Odeslaná',
        'confirmed'          => 'Potvrzená dodavatelem',
        'partially_received' => 'Částečně přijatá',
        'received'           => 'Přijatá',
        'closed'             => 'Uzavřená',
        'cancelled'          => 'Stornovaná',
    ];

    public function render(array $data): string
    {
        $order = $data['order'] ?? [];
        $data['state_label'] = self::STATE_LABELS[(string) ($order['state'] ?? '')] ?? '';

        $body = $this->renderTemplate('purchase_order.twig', $data);
        $mpdf = $this->mpdf([
            'format'        => 'A4',
            'orientation'   => 'P',
            'margin_left'   => 14,
            'margin_right'  => 14,
            'margin_top'    => 14,
            'margin_bottom' => 14,
        ]);
        $mpdf->SetTitle('Objednávka ' . (string) ($order['order_number'] ?? ''));
        $mpdf->WriteHTML($body);

        return $mpdf->Output('', 'S');
    }
}
