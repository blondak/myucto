<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF „Přehled OSVČ pro zdravotní pojišťovnu" (E11, audit 2026-07).
 *
 * Zrcadlí strukturu oficiálního formuláře Přehledu OSVČ zdravotní pojišťovny (výběr
 * pojišťovny dle kódu, číslo pojištěnce, vyměřovací základ, pojistné, zaplacené zálohy,
 * doplatek, nová měsíční záloha). Data = {@see \MyInvoice\Service\Tax\Return\InsuranceSummaryService::build()}
 * (větev `health`) — TÝŽ zdroj čísel jako sociální přehled ČSSZ (parita). A4 portrait.
 *
 * Slouží jako pomůcka k přepsání do formuláře/portálu ZP (jednotné veřejné strojové
 * schéma pro všechny pojišťovny neexistuje — proto PDF, ne XML).
 */
final class HealthInsuranceOverviewPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('health_insurance_overview.twig', $data);
        $mpdf = $this->mpdf([
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 14,
        ]);
        $mpdf->SetTitle('Přehled OSVČ pro zdravotní pojišťovnu ' . (string) ($data['summary']['year'] ?? ''));
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
