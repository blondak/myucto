<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Písemné potvrzení zaměstnavatele, že přijal sdělení zaměstnance o zdravotní
 * pojišťovně (§ 12 písm. b) zákona č. 48/1997 Sb.). A4 na výšku, bez patičky:
 * potvrzení je samo o sobě právní jednání (viz {@see ReportPdfRendererBase::withPageNumbers()}).
 *
 * Data = {@see \MyInvoice\Service\Payroll\PayrollHealthInsurerNoticeService::confirmationData()}.
 */
final class PayrollHealthInsurerNoticePdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $mpdf = $this->mpdf([
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 18,
            'margin_right' => 18,
            'margin_top' => 18,
            'margin_bottom' => 18,
        ]);
        $mpdf->SetTitle('Potvrzení o přijetí sdělení zdravotní pojišťovny');
        $mpdf->WriteHTML($this->renderTemplate('payroll_health_insurer_notice.twig', $data));

        return $mpdf->Output('', 'S');
    }
}
