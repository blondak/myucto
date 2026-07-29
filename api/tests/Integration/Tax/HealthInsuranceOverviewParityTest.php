<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Service\Pdf\ReportPdfRendererBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * E11 (audit 2026-07) — PDF „Přehled OSVČ pro zdravotní pojišťovnu" musí zobrazit
 * TÁTÁ čísla ze STEJNÉHO zdroje ({@see InsuranceSummaryService::build()} → větev
 * `health`) jako souhrnný přehled pojistného, který nese i sociální (ČSSZ) přehled.
 *
 * Test renderuje obě šablony ze SHODNÉHO summary a ověřuje, že zdravotní pojistné,
 * vyměřovací základ, doplatek i nová záloha jsou v obou identické (parita) a že ZP
 * šablona navíc nese výběr pojišťovny a číslo pojištěnce.
 */
#[Group('integration')]
final class HealthInsuranceOverviewParityTest extends TestCase
{
    /** Renderer vystavující renderTemplate() bez mPDF (jen HTML). */
    private function htmlRenderer(): ReportPdfRendererBase
    {
        return new class extends ReportPdfRendererBase {
            public function render(array $data): string
            {
                return $this->renderTemplate((string) $data['__template'], $data);
            }
        };
    }

    /** Summary ve tvaru InsuranceSummaryService::build() — jeden zdroj pro obě šablony. */
    private function summary(): array
    {
        return [
            'year' => 2025,
            'tax_base_7' => 480000.0,
            'is_secondary' => false,
            'social' => [
                'assessment_base' => 264000.0, 'min_base' => 160000.0, 'insurance' => 77088.0,
                'advances_paid' => 60000.0, 'balance_due' => 17088.0, 'monthly_advance' => 6424.0,
                'participates' => true, 'note' => '', 'sickness' => ['insured' => false, 'monthly_base' => 0.0, 'monthly_premium' => 0.0, 'annual' => 0.0],
            ],
            'health' => [
                'assessment_base' => 240000.0, 'min_base' => 216000.0, 'insurance' => 32400.0,
                'advances_paid' => 30000.0, 'balance_due' => 2400.0, 'monthly_advance' => 2723.0,
                'min_applies' => true, 'note' => '',
            ],
            'deadlines' => ['social' => '2026-05-01', 'health' => '2026-05-01', 'note' => 'Přehledy se podávají do 1 měsíce po lhůtě.'],
            'rates' => [
                'social' => 0.292, 'health' => 0.135, 'sickness' => 0.027,
                'social_assessment_pct' => 0.55, 'health_assessment_pct' => 0.50,
            ],
        ];
    }

    public function testHealthNumbersAreIdenticalInBothOverviews(): void
    {
        $summary = $this->summary();
        $renderer = $this->htmlRenderer();

        $socialHtml = $renderer->render([
            '__template' => 'insurance_summary.twig',
            'summary' => $summary,
            'supplier' => ['name' => 'Jan Novák', 'ic' => '', 'dic' => 'CZ7801011234'],
        ]);
        $zpHtml = $renderer->render([
            '__template' => 'health_insurance_overview.twig',
            'summary' => $summary,
            'supplier' => [
                'company_name' => 'Jan Novák', 'street' => 'Krátká 3', 'city' => 'Praha', 'zip' => '11000',
                'ic' => '', 'dic' => 'CZ7801011234',
                'health_insurance_number' => '7801011234', 'health_insurance_code' => '111',
            ],
            'insurer' => ['code' => '111', 'name' => 'Všeobecná zdravotní pojišťovna ČR (VZP)'],
        ]);

        // Zdravotní pojistné, VZ, doplatek a nová záloha (formát cz_money) v OBOU sestavách.
        foreach (['32 400,00', '240 000,00', '2 400,00', '2 723,00'] as $needle) {
            self::assertStringContainsString($needle, $socialHtml, "sociální souhrn musí obsahovat $needle");
            self::assertStringContainsString($needle, $zpHtml, "ZP přehled musí obsahovat $needle");
        }

        // ZP přehled navíc nese výběr pojišťovny a číslo pojištěnce.
        self::assertStringContainsString('Všeobecná zdravotní pojišťovna ČR (VZP)', $zpHtml);
        self::assertStringContainsString('7801011234', $zpHtml);
        self::assertStringContainsString('111', $zpHtml);
    }

    public function testMissingInsurerIsFlaggedNotFatal(): void
    {
        $html = $this->htmlRenderer()->render([
            '__template' => 'health_insurance_overview.twig',
            'summary' => $this->summary(),
            'supplier' => ['company_name' => 'Jan Novák', 'street' => 'Krátká 3', 'city' => 'Praha', 'zip' => '11000', 'health_insurance_number' => ''],
            'insurer' => ['code' => '', 'name' => ''],
        ]);
        self::assertStringContainsString('nevyplněna', $html);
        // I bez pojišťovny se čísla vyrenderují.
        self::assertStringContainsString('32 400,00', $html);
    }
}
