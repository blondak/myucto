<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Ruleset;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetYearCoverage;
use MyInvoice\Tests\Fixtures\Payroll\ShiftedYearPayrollRulesetFixture;
use PHPUnit\Framework\TestCase;

final class PayrollRulesetYearCoverageTest extends TestCase
{
    public function testOnlyYearsWithAnEffectiveRulesetAreCovered(): void
    {
        $rulesets = CzechPayrollRulesets2026::provider();

        self::assertSame(
            [2026],
            PayrollRulesetYearCoverage::years($rulesets, PayrollRulesetDomain::IncomeTax),
        );
        self::assertFalse(PayrollRulesetYearCoverage::coversYear(
            $rulesets,
            PayrollRulesetDomain::IncomeTax,
            2027,
        ));
    }

    public function testTwoConsecutiveIntervalsCoverTheWholeYear(): void
    {
        $rulesets = CzechPayrollRulesets2026::provider();

        self::assertSame(
            [2026],
            PayrollRulesetYearCoverage::years($rulesets, PayrollRulesetDomain::TravelAllowances),
        );
    }

    public function testPartiallyCoveredYearIsNotCovered(): void
    {
        $rulesets = self::providerCovering('2026-01-01', '2026-06-30');

        self::assertTrue(PayrollRulesetYearCoverage::coversDate(
            $rulesets,
            PayrollRulesetDomain::CompensationAverages,
            '2026-03-01',
        ));
        self::assertFalse(PayrollRulesetYearCoverage::coversYear(
            $rulesets,
            PayrollRulesetDomain::CompensationAverages,
            2026,
        ));
        self::assertSame(
            [],
            PayrollRulesetYearCoverage::years($rulesets, PayrollRulesetDomain::CompensationAverages),
        );
    }

    public function testAssertYearExplainsExactlyWhatIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Pro 2027 není účinný mzdový ruleset domény income_tax;'
            . ' doplň ho v administraci mzdových rulesetů — bez něj mzdový modul nepočítá.',
        );

        PayrollRulesetYearCoverage::assertYear(
            CzechPayrollRulesets2026::provider(),
            PayrollRulesetDomain::IncomeTax,
            2027,
        );
    }

    public function testCommonYearsRequireEveryDomain(): void
    {
        $rulesets = ShiftedYearPayrollRulesetFixture::provider(2027);

        self::assertSame([2026, 2027], PayrollRulesetYearCoverage::commonYears($rulesets, [
            PayrollRulesetDomain::IncomeTax,
            PayrollRulesetDomain::CompensationAverages,
        ]));
    }

    private static function providerCovering(string $from, string $to): PayrollRulesetProvider
    {
        $base = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::CompensationAverages, '2026-08-03');

        return new PayrollRulesetProvider([
            new PayrollRulesetVersion(
                $base->id . '.partial',
                $base->version,
                $base->domain,
                $from,
                $to,
                $base->lifecycle,
                $base->capability,
                $base->sources,
                $base->parameters,
                $base->approval,
                $base->technicalReview,
            ),
        ]);
    }
}
