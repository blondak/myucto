<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Ruleset;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetCapability;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use PHPUnit\Framework\TestCase;

final class CzechPayrollRulesets2026Test extends TestCase
{
    private const EXPECTED_MANIFEST_SHA256 = 'e4252c4a671c57849199b4699df103136d29257c4124b7ccbf37e2d348d2cd96';

    public function testCanonicalManifestIsByteStable(): void
    {
        $provider = CzechPayrollRulesets2026::provider();
        $first = $provider->canonicalManifestJson();
        $second = CzechPayrollRulesets2026::provider()->canonicalManifestJson();

        self::assertSame($first, $second);
        self::assertSame(self::EXPECTED_MANIFEST_SHA256, hash('sha256', $first));
    }

    public function testAmountsUseMinorUnitsAndRatesUseCanonicalStrings(): void
    {
        $provider = CzechPayrollRulesets2026::provider();
        $health = $provider->forDate(PayrollRulesetDomain::HealthInsurance, '2026-08-03');
        $employment = $provider->forDate(PayrollRulesetDomain::EmploymentThresholds, '2026-08-03');

        self::assertSame(302_400, $health->parameter('minimum_contribution.monthly')->value);
        self::assertSame('0.135', $health->parameter('total.rate')->value);
        self::assertSame(2_240_000, $employment->parameter('minimum_wage.monthly_40h_week')->value);
        self::assertSame(13_440, $employment->parameter('minimum_wage.hourly_40h_week')->value);
    }

    public function testEverySourceHasRetrievalDateAndOfficialHttpsUrl(): void
    {
        $provider = CzechPayrollRulesets2026::provider();
        foreach (PayrollRulesetDomain::cases() as $domain) {
            $ruleset = $provider->forDate($domain, '2026-08-03');
            foreach ($ruleset->sources as $source) {
                self::assertSame(CzechPayrollRulesets2026::RETRIEVED_ON, $source->retrievedOn);
                self::assertStringStartsWith('https://', $source->url);
                self::assertMatchesRegularExpression(
                    '/\.(cssz|mpsv|gov|vzp|e-sbirka|justice)\.cz|^https:\/\/financnisprava\.gov\.cz/',
                    $source->url,
                );
            }
        }
    }

    public function testSnapshotCarriesIdentityLifecycleSourcesAndApproval(): void
    {
        $ruleset = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::IncomeTax, '2026-08-03');
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode($ruleset->canonicalSnapshot, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('cz-payroll-2026.income-tax.v1', $snapshot['id']);
        self::assertSame('2026.1.0', $snapshot['version']);
        self::assertSame('reviewed', $snapshot['lifecycle']);
        self::assertSame('2026-01-01', $snapshot['effective_from']);
        self::assertSame('2026-12-31', $snapshot['effective_to']);
        self::assertNull($snapshot['approval']);
        self::assertNotEmpty($snapshot['technical_review']);
        self::assertNotEmpty($snapshot['sources']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $ruleset->canonicalHash);
    }

    public function testCanonicalSupportedParameterMatrixAndManualReviewBoundary(): void
    {
        [$supported, $manualReview] = $this->canonicalParameterMatrix(
            CzechPayrollRulesets2026::provider(),
        );

        self::assertSame([
            'income_tax' => [
                'advance.high_rate' => ['decimal_rate', '0.23'],
                'advance.high_threshold.monthly' => ['money_minor', 14_690_100],
                'advance.low_rate' => ['decimal_rate', '0.15'],
                'advance.rounding.base_above_100_czk' => ['text', 'ceil-to-100-czk'],
                'advance.rounding.base_up_to_100_czk' => ['text', 'ceil-to-1-czk'],
                'advance.rounding.result' => ['text', 'ceil-to-1-czk'],
                'bonus.minimum_amount.monthly' => ['money_minor', 5_000],
                'bonus.minimum_income.monthly' => ['money_minor', 1_120_000],
                'bonus.minimum_income.yearly' => ['money_minor', 13_440_000],
                'credit.child.first.monthly' => ['money_minor', 126_700],
                'credit.child.second.monthly' => ['money_minor', 186_000],
                'credit.child.third_and_next.monthly' => ['money_minor', 232_000],
                'credit.disability.basic.monthly' => ['money_minor', 21_000],
                'credit.disability.extended.monthly' => ['money_minor', 42_000],
                'credit.taxpayer.monthly' => ['money_minor', 257_000],
                'credit.ztp_p.monthly' => ['money_minor', 134_500],
                'dpp.withholding.maximum' => ['money_minor', 1_199_900],
                'other.withholding.maximum' => ['money_minor', 449_900],
                'withholding.rate' => ['decimal_rate', '0.15'],
            ],
            'social_insurance' => [
                'employee.discount.working_pensioner' => ['decimal_rate', '0.065'],
                'employee.rate.ordinary' => ['decimal_rate', '0.071'],
                'employer.discount.part_time' => ['decimal_rate', '0.05'],
                'employer.rate.ordinary' => ['decimal_rate', '0.248'],
                'maximum_assessment_base.yearly' => ['money_minor', 235_041_600],
                'participation.dpp.minimum' => ['money_minor', 1_200_000],
                'participation.small_scale.minimum' => ['money_minor', 450_000],
            ],
            'health_insurance' => [
                'employee.rate' => ['decimal_rate', '0.045'],
                'employer.rate' => ['decimal_rate', '0.09'],
                'minimum_assessment_base.monthly' => ['money_minor', 2_240_000],
                'minimum_contribution.monthly' => ['money_minor', 302_400],
                'participation.dpc.minimum' => ['money_minor', 450_000],
                'participation.dpp.minimum' => ['money_minor', 1_200_000],
                'rounding.total' => ['text', 'ceil-to-1-czk'],
                'total.rate' => ['decimal_rate', '0.135'],
            ],
            'employment_thresholds' => [
                'average_wage.monthly' => ['money_minor', 4_896_700],
                'minimum_wage.hourly_40h_week' => ['money_minor', 13_440],
                'minimum_wage.monthly_40h_week' => ['money_minor', 2_240_000],
                'participation.dpc.minimum' => ['money_minor', 450_000],
                'participation.dpp.minimum' => ['money_minor', 1_200_000],
                'participation.small_scale.minimum' => ['money_minor', 450_000],
            ],
        ], $supported, 'A supported 2026 payroll parameter changed or crossed the capability boundary.');

        self::assertSame([
            'income_tax' => [],
            'social_insurance' => [
                'employee.discount.agriculture_dpp' =>
                    'Eligibility depends on the statutory agriculture conditions and must be reviewed.',
                'employer.rate.rescue_and_company_fire_service' =>
                    'The 0.298 rate is official, but occupational classification requires manual review.',
                'employer.rate.risk_employment' =>
                    'The 0.278 rate is official, but risk-employment classification requires manual review.',
            ],
            'health_insurance' => [],
            'employment_thresholds' => [],
        ], $manualReview, 'A manual-review parameter changed or became silently calculation-ready.');
    }

    /**
     * @return array{
     *   array<string, array<string, array{string, bool|int|string}>>,
     *   array<string, array<string, string>>
     * }
     */
    private function canonicalParameterMatrix(PayrollRulesetProvider $provider): array
    {
        $supported = [];
        $manualReview = [];
        foreach ([
            PayrollRulesetDomain::IncomeTax,
            PayrollRulesetDomain::SocialInsurance,
            PayrollRulesetDomain::HealthInsurance,
            PayrollRulesetDomain::EmploymentThresholds,
        ] as $domain) {
            $ruleset = $provider->forDate($domain, '2026-08-03');
            $supported[$domain->value] = [];
            $manualReview[$domain->value] = [];
            foreach ($ruleset->parameters as $key => $parameter) {
                if ($parameter->capability === PayrollRulesetCapability::Supported) {
                    $supported[$domain->value][$key] = [$parameter->type, $parameter->value];
                    continue;
                }

                $manualReview[$domain->value][$key] = (string) $parameter->note;
            }
        }

        return [$supported, $manualReview];
    }
}
