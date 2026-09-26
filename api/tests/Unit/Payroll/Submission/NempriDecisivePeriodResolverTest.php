<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Migration\PayrollTakeoverMonth;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriDecisiveMonth;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriDecisivePeriodResolver;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use PHPUnit\Framework\TestCase;

/**
 * Rozhodné období do NEMPRI: měsíce, které nepokrývá měsíční hlášení
 * podané z MyÚčta, se berou z převzatých mezd (nebo z ručního doplnění).
 *
 * Dřív věta rozhodné období nenesla NIKDY — a ÚSSZ pak u sociální události
 * v roce přechodu počítala dávku z minimální mzdy, protože měsíce před
 * rokem 2026 ani měsíce vedené původním programem v hlášení z MyÚčta nebyly.
 */
final class NempriDecisivePeriodResolverTest extends TestCase
{
    private NempriDecisivePeriodResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new NempriDecisivePeriodResolver();
    }

    /**
     * Firma přešla na MyÚčto v červenci 2026. Sociální událost v říjnu:
     * rozhodné období 10/2025–9/2026, měsíce 10/2025–6/2026 z převzatých mezd,
     * 7–9/2026 vykázalo hlášení z MyÚčta, takže ve větě nejsou.
     */
    public function testTransitionYearSendsMonthsBeforePayrollStartFromTakeover(): void
    {
        $takeover = [];
        foreach (['2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'] as $period) {
            $takeover[] = $this->month($period, 3_000_000, $period === '2026-02' ? 5 : 0);
        }

        $result = $this->resolver->resolve(
            '2026-10-12',
            '2019-03-01',
            '2026-07',
            $takeover,
            [],
            null,
        );

        self::assertNotNull($result);
        self::assertSame('2025-10-01', $result->from);
        self::assertSame('2026-09-30', $result->to);
        self::assertSame(
            ['2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'],
            array_map(static fn (NempriDecisiveMonth $month): string => $month->period(), $result->months),
        );
        self::assertSame(5, $result->months[4]->excludedDays);
        // Červenec až září jsou v hlášení z MyÚčta — seznam není úplný.
        self::assertFalse($result->complete);
        self::assertNull($result->probableIncomeCzk);
    }

    /**
     * Firma vede mzdy v MyÚčtu od začátku roku 2026. Měsíce roku 2025 v žádném
     * měsíčním hlášení nejsou — ty jdou do věty, 2026 ne.
     */
    public function testMonthsBefore2026AreAlwaysSent(): void
    {
        $takeover = [];
        foreach (['2025-03', '2025-04', '2025-05', '2025-06', '2025-07', '2025-08', '2025-09', '2025-10', '2025-11', '2025-12'] as $period) {
            $takeover[] = $this->month($period, 2_500_000, 0);
        }

        $result = $this->resolver->resolve('2026-03-02', '2015-01-01', '2026-01', $takeover, [], null);

        self::assertNotNull($result);
        self::assertCount(10, $result->months);
        self::assertSame('2025-03', $result->months[0]->period());
        self::assertSame('2025-12', $result->months[9]->period());
    }

    public function testMissingTakeoverMonthStopsWithTheMonthNamed(): void
    {
        try {
            $this->resolver->resolve(
                '2026-03-02',
                '2015-01-01',
                '2026-01',
                [$this->month('2025-03', 2_500_000, 0)],
                [],
                null,
            );
            self::fail('Chybějící měsíc rozhodného období se nesmí tiše vynechat.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_decisive_month_missing', $exception->validationCode);
            self::assertStringContainsString('2025-04', $exception->getMessage());
        }
    }

    public function testManualMonthWinsOverTakeover(): void
    {
        $result = $this->resolver->resolve(
            '2026-02-10',
            '2025-12-01',
            '2026-01',
            [$this->month('2025-12', 1_000_000, 0)],
            ['2025-12' => ['income_minor' => 2_000_000, 'excluded_days' => 3]],
            null,
        );

        self::assertNotNull($result);
        self::assertCount(1, $result->months);
        self::assertSame(2_000_000, $result->months[0]->countableIncomeMinor);
        self::assertSame(NempriDecisiveMonth::SOURCE_MANUAL, $result->months[0]->source);
    }

    /**
     * Nástup 20. 5., sociální událost v červnu: rozhodné období má 12 dnů,
     * a tak ÚSSZ vychází z pravděpodobného příjmu. Bez něj se věta nesestaví.
     */
    public function testShortDecisivePeriodNeedsProbableIncome(): void
    {
        try {
            $this->resolver->resolve('2026-06-05', '2026-05-20', '2026-01', [], [], null);
            self::fail('Krátké rozhodné období bez pravděpodobného příjmu nesmí projít.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_probable_income_missing', $exception->validationCode);
        }

        $result = $this->resolver->resolve('2026-06-05', '2026-05-20', '2026-01', [], [], 42_000);

        self::assertNotNull($result);
        self::assertSame('2026-05-20', $result->from);
        self::assertSame('2026-05-31', $result->to);
        self::assertSame([], $result->months);
        self::assertSame(42_000, $result->probableIncomeCzk);
    }

    public function testEventInFirstMonthOfEmploymentUsesStartDay(): void
    {
        $result = $this->resolver->resolve('2026-06-15', '2026-06-01', '2026-01', [], [], 38_000);

        self::assertNotNull($result);
        self::assertSame('2026-06-01', $result->from);
        self::assertSame('2026-06-01', $result->to);
        self::assertSame(38_000, $result->probableIncomeCzk);
    }

    /** Všechny měsíce vykázalo hlášení z MyÚčta — věta rozhodné období nenese. */
    public function testFullyReportedDecisivePeriodIsOmitted(): void
    {
        self::assertNull(
            $this->resolver->resolve('2027-03-10', '2020-01-01', '2026-01', [], [], null),
        );
    }

    public function testCompleteListCarriesTotals(): void
    {
        $result = $this->resolver->resolve(
            '2025-12-10',
            '2025-10-01',
            null,
            [$this->month('2025-10', 1_000_000, 0), $this->month('2025-11', 1_100_000, 2)],
            [],
            null,
        );

        self::assertNotNull($result);
        self::assertTrue($result->complete);
    }

    private function month(string $period, int $socialBaseMinor, int $excludedDays): PayrollTakeoverMonth
    {
        return PayrollTakeoverMonth::fromRow([
            'period' => $period . '-01',
            'source' => 'other',
            'external_person_ref' => 'P1',
            'external_relationship_ref' => 'R1',
            'employee_id' => 1,
            'employment_id' => 7,
            'social_base_minor' => $socialBaseMinor,
            'excluded_days' => $excludedDays,
        ]);
    }
}
