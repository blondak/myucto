<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\HealthInsurance\HealthComponentTreatment;
use MyInvoice\Service\Payroll\HealthInsurance\HealthCorrectionTreatment;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponentTreatment;
use MyInvoice\Service\Payroll\IncomeTax\TaxCorrectionTreatment;
use MyInvoice\Service\Payroll\Run\PayrollRunStatutoryComponentMapper;
use MyInvoice\Service\Payroll\SocialInsurance\SocialComponentTreatment;
use PHPUnit\Framework\TestCase;

final class PayrollRunStatutoryComponentMapperTest extends TestCase
{
    public function testMapsIndependentFrozenClassifications(): void
    {
        $mapper = new PayrollRunStatutoryComponentMapper();
        $input = $this->input([
            'tax_treatment' => 'withholding_candidate',
            'social_participation_treatment' => 'included',
            'social_treatment' => 'excluded',
            'health_participation_treatment' => 'excluded',
            'health_treatment' => 'included',
        ]);

        $social = $mapper->social($input);
        $health = $mapper->health($input, '2026-08-01');
        $tax = $mapper->incomeTax($input, '2026-08-01');

        self::assertSame('input.42.mzda_mesicni', $social->code);
        self::assertSame(SocialComponentTreatment::Included, $social->participationTreatment);
        self::assertSame(SocialComponentTreatment::Excluded, $social->assessmentBaseTreatment);
        self::assertSame(HealthComponentTreatment::Excluded, $health->participationTreatment);
        self::assertSame(HealthComponentTreatment::Included, $health->assessmentBaseTreatment);
        self::assertSame(IncomeTaxComponentTreatment::Included, $tax->treatment);
    }

    public function testPriorSourcePeriodFailsClosedForHealthAndTax(): void
    {
        $mapper = new PayrollRunStatutoryComponentMapper();
        $input = $this->input([], '2026-07-01');

        self::assertSame(
            HealthCorrectionTreatment::Unverified,
            $mapper->health($input, '2026-08-01')->correctionTreatment,
        );
        self::assertSame(
            TaxCorrectionTreatment::Unverified,
            $mapper->incomeTax($input, '2026-08-01')->correctionTreatment,
        );
    }

    public function testTaxExemptionDoesNotInventVerifiedEvidence(): void
    {
        $component = (new PayrollRunStatutoryComponentMapper())->incomeTax(
            $this->input(['tax_treatment' => 'exempt']),
            '2026-08-01',
        );

        self::assertSame(IncomeTaxComponentTreatment::Exempt, $component->treatment);
        self::assertFalse($component->hasVerifiedTreatmentEvidence('2026-08-01'));
    }

    /**
     * @param array<string,string> $overrides
     * @return array<string,mixed>
     */
    private function input(array $overrides = [], ?string $sourcePeriod = null): array
    {
        return [
            'id' => 42,
            'amount_minor' => 125_000,
            'source_period_start' => $sourcePeriod,
            'component' => [
                'code' => 'MZDA_MESICNI',
                'tax_treatment' => 'included',
                'social_participation_treatment' => 'included',
                'social_treatment' => 'included',
                'health_participation_treatment' => 'included',
                'health_treatment' => 'included',
                ...$overrides,
            ],
        ];
    }
}
