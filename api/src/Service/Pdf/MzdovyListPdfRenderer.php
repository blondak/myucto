<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF mzdového listu (§38j ZDP, A4 landscape) — povinná roční evidence za
 * zaměstnance/jednatele-společníka: identifikace poplatníka + měsíční rozpad mzdy
 * a slev + roční úhrn.
 *
 * Data = výstup {@see \MyInvoice\Service\Accounting\Payroll\PayrollSheetService::build()}.
 */
final class MzdovyListPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('payroll_sheet.twig', $data);
        $mpdf = $this->mpdf();
        $employeeName = (string) ($data['employee']['full_name'] ?? '');
        $year = (string) ($data['year'] ?? '');
        $mpdf->SetTitle('Mzdový list ' . $year . ' — ' . $employeeName);
        $this->withPageNumbers($mpdf, 'Mzdový list ' . $year . ' — ' . $employeeName);
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
