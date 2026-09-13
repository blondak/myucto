<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Tisková sestava mzdových vstupů (A4 na šířku): skupiny po zaměstnancích
 * s mezisoučty a na konci rekapitulace podle složky.
 *
 * Data = {@see \MyInvoice\Service\Payroll\Export\PayrollInputExportService::pdfData()}.
 *
 * HTML se do mPDF posílá po částech, jedna skupina zaměstnance na volání.
 * Pět tisíc řádků v jednom WriteHTML by přerostlo `pcre.backtrack_limit`
 * a mPDF by sestavu odmítl; takhle je každá část malá.
 */
final class PayrollInputsPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $mpdf = $this->mpdf();
        $mpdf->simpleTables = true;
        $mpdf->packTableData = true;
        $title = 'Mzdové vstupy ' . (string) ($data['period_label'] ?? '');
        $mpdf->SetTitle($title);
        $this->withPageNumbers($mpdf, $title);
        foreach ($this->renderParts($data) as $part) {
            $mpdf->WriteHTML($part);
        }

        return $mpdf->Output('', 'S');
    }

    /**
     * Celé vysázené tělo sestavy, aby šlo ověřit, co na papíře stojí.
     *
     * @param array<string,mixed> $data
     */
    public function renderHtml(array $data): string
    {
        return implode("\n", $this->renderParts($data));
    }

    /**
     * @param array<string,mixed> $data
     * @return list<string>
     */
    private function renderParts(array $data): array
    {
        $parts = [$this->renderTemplate('payroll_inputs_head.twig', $data)];
        foreach ((array) ($data['groups'] ?? []) as $group) {
            $parts[] = $this->renderTemplate('payroll_inputs_group.twig', ['group' => $group]);
        }
        $parts[] = $this->renderTemplate('payroll_inputs_recap.twig', $data);

        return $parts;
    }
}
