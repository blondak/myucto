<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Termination;

use MyInvoice\Service\Payroll\Document\AverageEarningsCertificateDocumentData;
use MyInvoice\Service\Payroll\Termination\PayrollSeverancePolicy;
use MyInvoice\Service\Payroll\Termination\PayrollTerminationReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Jediný zdroj důvodu skončení: z jednoho záznamu musí vyjít kód pro A2
 * (číselník „CIS Důvod ukončení PPV"), druh pro potvrzení Úřadu práce
 * a nárok na odstupné. Dřív se A2 a potvrzení vyplňovaly nezávisle.
 */
final class PayrollTerminationReasonTest extends TestCase
{
    /** @return iterable<string,array{string,string,string,string}> */
    public static function mapping(): iterable
    {
        yield 'dohoda bez důvodu' => ['agreement', 'none', '2', 'agreement'];
        yield 'dohoda z organizačních důvodů' => ['agreement', 'organizational', '4', 'organizational'];
        yield 'výpověď zaměstnance' => ['employee_notice', 'none', '3', 'employee_unilateral'];
        yield 'výpověď pro nadbytečnost' => ['employer_notice', 'organizational', '4', 'organizational'];
        yield 'výpověď ze zdravotních důvodů' => ['employer_notice', 'health_long_term', '5', 'health'];
        yield 'výpověď — nejvyšší expozice' => ['employer_notice', 'max_exposure', '5', 'health'];
        yield 'nesplňuje předpoklady' => ['employer_notice', 'requirements_unmet', '6', 'none'];
        yield 'zvlášť hrubé porušení' => ['employer_immediate', 'breach_gross', '7', 'gross_breach'];
        yield 'závažné porušení (ne zvlášť hrubé)' => ['employer_notice', 'breach_serious', '7', 'none'];
        yield 'soustavné méně závažné' => ['employer_notice', 'breach_minor_repeated', '8', 'none'];
        yield 'režim DPN' => ['employer_notice', 'sickness_regime', '9', 'sickness_regime_breach'];
        yield 'odsouzení' => ['employer_immediate', 'criminal_conviction', '10', 'none'];
        yield 'nevyplacená mzda' => ['employee_immediate', 'wage_not_paid', '11', 'employer_breach'];
        yield 'zdraví bez převedení' => ['employee_immediate', 'health_no_transfer', '5', 'health'];
        yield 'doba určitá' => ['fixed_term_expiry', 'none', '12', 'none'];
        yield 'zkušební doba zaměstnavatel' => ['probation_employer', 'none', '13', 'none'];
        yield 'zkušební doba zaměstnanec' => ['probation_employee', 'none', '14', 'none'];
        yield 'úmrtí' => ['death', 'none', '15', 'none'];
    }

    #[DataProvider('mapping')]
    public function testOneRecordDrivesBothFilings(string $method, string $ground, string $code, string $kind): void
    {
        $reason = new PayrollTerminationReason($method, $ground);

        self::assertSame($code, $reason->regzecReasonCode());
        self::assertSame($kind, $reason->unemploymentOfficeKind());
        self::assertContains($kind, AverageEarningsCertificateDocumentData::TERMINATION_REASONS);
    }

    public function testGroundMustFitTheMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PayrollTerminationReason('employee_notice', 'organizational');
    }

    /**
     * DIS přijímá údaj o odstupném jen u důvodu 4 a 5. Dohoda z organizačních
     * důvodů proto musí jít jako 4, jinak by odstupné nešlo ohlásit.
     */
    public function testSettlementIsReportableOnlyForCodesFourAndFive(): void
    {
        self::assertTrue((new PayrollTerminationReason('agreement', 'organizational'))->settlementReportable());
        self::assertTrue((new PayrollTerminationReason('employer_notice', 'health_work_injury'))->settlementReportable());
        self::assertFalse((new PayrollTerminationReason('agreement', 'none'))->settlementReportable());
        self::assertFalse((new PayrollTerminationReason('fixed_term_expiry', 'none'))->settlementReportable());
    }

    public function testSeveranceMultiplesFollowTenure(): void
    {
        $reason = new PayrollTerminationReason('employer_notice', 'organizational');

        self::assertSame(1, PayrollSeverancePolicy::statutory($reason, '2026-01-01', '2026-12-30', [], false)['multiple']);
        self::assertSame(2, PayrollSeverancePolicy::statutory($reason, '2026-01-01', '2026-12-31', [], false)['multiple']);
        self::assertSame(2, PayrollSeverancePolicy::statutory($reason, '2025-01-01', '2026-12-30', [], false)['multiple']);
        self::assertSame(3, PayrollSeverancePolicy::statutory($reason, '2025-01-01', '2026-12-31', [], false)['multiple']);
        self::assertSame(6, PayrollSeverancePolicy::statutory($reason, '2025-01-01', '2026-12-31', [], true)['multiple']);
    }

    /** § 67 odst. 2: předchozí poměr s mezerou do šesti měsíců se započte svou délkou. */
    public function testPreviousEmploymentWithinSixMonthsCounts(): void
    {
        $reason = new PayrollTerminationReason('agreement', 'organizational');
        $previous = [['start' => '2024-01-01', 'end' => '2024-06-30']];

        $alone = PayrollSeverancePolicy::statutory($reason, '2024-10-01', '2025-06-30', [], false);
        self::assertSame(1, $alone['multiple']);

        $counted = PayrollSeverancePolicy::statutory($reason, '2024-10-01', '2025-06-30', $previous, false);
        self::assertSame(2, $counted['multiple']);
        self::assertCount(1, $counted['counted_previous']);

        $tooLate = PayrollSeverancePolicy::statutory($reason, '2025-02-01', '2025-06-30', $previous, false);
        self::assertSame(1, $tooLate['multiple']);
        self::assertSame([], $tooLate['counted_previous']);
    }

    public function testTwelveFoldCases(): void
    {
        $exposure = PayrollSeverancePolicy::statutory(
            new PayrollTerminationReason('employer_notice', 'max_exposure'),
            '2026-01-01',
            '2026-03-31',
            [],
            false,
        );
        self::assertSame([12, 'zp-67-3'], [$exposure['multiple'], $exposure['rule']]);

        $injury = PayrollSeverancePolicy::statutory(
            new PayrollTerminationReason('agreement', 'health_work_injury'),
            '2026-01-01',
            '2026-03-31',
            [],
            false,
        );
        self::assertSame([12, 'zp-271ca-1'], [$injury['multiple'], $injury['rule']]);

        $plainHealth = PayrollSeverancePolicy::statutory(
            new PayrollTerminationReason('employer_notice', 'health_long_term'),
            '2026-01-01',
            '2026-03-31',
            [],
            false,
        );
        self::assertSame(0, $plainHealth['multiple'], 'Zdravotní důvod bez úrazu odstupné nezakládá.');
    }

    public function testAmountIsRoundedUpToWholeCrowns(): void
    {
        self::assertSame(13_044_100, PayrollSeverancePolicy::amount(3, 4_348_001));
        self::assertSame(13_044_000, PayrollSeverancePolicy::amount(3, 4_348_000));
    }
}
