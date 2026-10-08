<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Migration\PayrollTakeoverMonth;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriDecisiveMonth;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriDecisivePeriod;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriDecisivePeriodResolver;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriDecisiveSources;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use PHPUnit\Framework\TestCase;

/**
 * Rozhodné období do NEMPRI: věta nese období vždy úplné (každý měsíc se
 * započitatelným příjmem a vyloučenými dny, k tomu oba součty), nebo jen
 * pravděpodobnou výši příjmu. Měsíce se berou z ručního doplnění, ze
 * schválených mzdových běhů a z převzatých mezd.
 *
 * Dřív věta vynechávala měsíce pokryté vlastním měsíčním hlášením, posílala
 * neúplný seznam bez součtů a od roku 2027 rozhodné období vůbec — v rozporu
 * s DV NEMPRI25 a logickými kontrolami 7, 8 a 16.
 */
final class NempriDecisivePeriodResolverTest extends TestCase
{
    private NempriDecisivePeriodResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new NempriDecisivePeriodResolver();
    }

    /**
     * NRO-01, scénář 1: mzdy v MyÚčtu od ledna 2026, událost 5. 10. 2026.
     * Věta nese všech 12 měsíců (10–12/2025 z převzatých mezd, 1–9/2026
     * z mzdových běhů) a oba součty. Dřív jen tři měsíce bez součtů.
     */
    public function testDecisivePeriodCarriesAllTwelveMonthsWithTotals(): void
    {
        $takeover = [];
        foreach (['2025-10', '2025-11', '2025-12'] as $period) {
            $takeover[] = $this->month($period, 3_000_000, 0, 0);
        }
        $payroll = [];
        foreach (range(1, 9) as $month) {
            $payroll[sprintf('2026-%02d', $month)] = ['income_minor' => 3_200_000, 'excluded_days' => $month === 3 ? 4 : 0];
        }

        $result = $this->resolver->resolve(
            '2026-10-05',
            '2019-03-01',
            '2026-01',
            NempriDecisiveSources::fromArrays($takeover, [], $payroll),
            null,
        );

        self::assertSame('2025-10-01', $result->from);
        self::assertSame('2026-09-30', $result->to);
        self::assertCount(12, $result->months);
        self::assertTrue($result->complete);
        self::assertNull($result->probableIncomeCzk);
        self::assertSame(3 * 3_000_000 + 9 * 3_200_000, $result->incomeMinor());
        self::assertSame(4, $result->excludedDays());
        self::assertSame(NempriDecisiveMonth::SOURCE_TAKEOVER, $result->months[0]->source);
        self::assertSame(NempriDecisiveMonth::SOURCE_PAYROLL, $result->months[3]->source);
    }

    /** NRO-01, scénář 2: událost v roce 2027 nesmí rozhodné období z věty vypustit. */
    public function testEventIn2027StillCarriesDecisivePeriod(): void
    {
        $payroll = [];
        for ($cursor = new \DateTimeImmutable('2026-02-01'); $cursor <= new \DateTimeImmutable('2027-01-01'); $cursor = $cursor->modify('+1 month')) {
            $payroll[$cursor->format('Y-m')] = ['income_minor' => 4_000_000, 'excluded_days' => 0];
        }

        $result = $this->resolver->resolve(
            '2027-02-10',
            '2020-01-01',
            '2026-01',
            NempriDecisiveSources::fromArrays([], [], $payroll),
            null,
        );

        self::assertSame('2026-02-01', $result->from);
        self::assertSame('2027-01-31', $result->to);
        self::assertCount(12, $result->months);
        self::assertTrue($result->complete);
    }

    /**
     * NRO-01, scénář 3: zaměstnání od 20. 12. 2025, událost v lednu 2026.
     * Období má 12 dnů, takže věta nese jen pravděpodobný příjem — žádné
     * měsíce ani součty vedle něj (kontroly 8 a 16).
     */
    public function testShortPeriodCarriesOnlyProbableIncome(): void
    {
        $result = $this->resolver->resolve(
            '2026-01-12',
            '2025-12-20',
            '2026-01',
            NempriDecisiveSources::fromArrays([$this->month('2025-12', 1_000_000, 0, 0)], []),
            36_000,
        );

        self::assertSame('2025-12-20', $result->from);
        self::assertSame('2025-12-31', $result->to);
        self::assertSame([], $result->months);
        self::assertFalse($result->complete);
        self::assertSame(36_000, $result->probableIncomeCzk);
    }

    public function testMonthWithoutAnySourceStopsWithTheMonthNamed(): void
    {
        try {
            $this->resolver->resolve(
                '2026-03-02',
                '2015-01-01',
                '2026-01',
                NempriDecisiveSources::fromArrays(
                    array_map(fn (int $m): PayrollTakeoverMonth => $this->month(sprintf('2025-%02d', $m), 2_500_000, 0, 0), range(3, 12)),
                    [],
                ),
                null,
            );
            self::fail('Měsíc bez podkladu se nesmí tiše vynechat.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_decisive_month_missing', $exception->validationCode);
            self::assertStringContainsString('2026-01, 2026-02', $exception->getMessage());
            self::assertStringContainsString('schvalte', $exception->getMessage());
        }
    }

    public function testManualMonthWinsOverOtherSources(): void
    {
        $result = $this->resolver->resolve(
            '2026-02-10',
            '2025-12-01',
            '2026-01',
            NempriDecisiveSources::fromArrays(
                [$this->month('2025-12', 1_000_000, 0, 0)],
                [
                    '2025-12' => ['income_minor' => 2_000_000, 'excluded_days' => 3],
                    '2026-01' => ['income_minor' => 2_100_000, 'excluded_days' => 0],
                ],
                ['2026-01' => ['income_minor' => 5_000_000, 'excluded_days' => 0]],
            ),
            null,
        );

        self::assertCount(2, $result->months);
        self::assertSame(2_000_000, $result->months[0]->countableIncomeMinor);
        self::assertSame(2_100_000, $result->months[1]->countableIncomeMinor);
        self::assertSame(NempriDecisiveMonth::SOURCE_MANUAL, $result->months[1]->source);
    }

    /** § 19 odst. 7: období podle § 18 odst. 4 kratší než 30 dnů. */
    public function testShortDecisivePeriodNeedsProbableIncome(): void
    {
        try {
            $this->resolver->resolve('2026-06-05', '2026-05-20', '2026-01', NempriDecisiveSources::fromArrays([], []), null);
            self::fail('Krátké rozhodné období bez pravděpodobného příjmu nesmí projít.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_probable_income_missing', $exception->validationCode);
        }

        $result = $this->resolver->resolve('2026-06-05', '2026-05-20', '2026-01', NempriDecisiveSources::fromArrays([], []), 42_000);

        self::assertSame('2026-05-20', $result->from);
        self::assertSame('2026-05-31', $result->to);
        self::assertSame([], $result->months);
        self::assertSame(42_000, $result->probableIncomeCzk);
    }

    /**
     * § 18 odst. 5: událost v měsíci vzniku pojištění. Rozhodné období se
     * neurčuje a věta podle Všeobecných zásad NEMPRI nese od = den nástupu,
     * do = den před vznikem události (NEMPRI25-rozhodneObdobi.pravdepodobnaVysePrijmu-3).
     * Událost v den nástupu nechá do = den nástupu.
     */
    public function testEventInFirstMonthOfEmploymentUsesStartDay(): void
    {
        $result = $this->resolver->resolve('2026-06-15', '2026-06-03', '2026-01', NempriDecisiveSources::fromArrays([], []), 38_000);

        self::assertSame('2026-06-03', $result->from);
        self::assertSame('2026-06-14', $result->to);
        self::assertSame(38_000, $result->probableIncomeCzk);

        $sameDay = $this->resolver->resolve('2026-06-03', '2026-06-03', '2026-01', NempriDecisiveSources::fromArrays([], []), 38_000);
        self::assertSame('2026-06-03', $sameDay->from);
        self::assertSame('2026-06-03', $sameDay->to);
    }

    /**
     * NRO-03 (§ 19 odst. 11): konec zaměstnání 28. 6., neschopnost 2. 7.
     * v ochranné lhůtě. Rozhodným dnem je 29. 6., takže období je
     * 6/2025–5/2026, ne 7/2025–6/2026.
     */
    public function testProtectionPeriodUsesDayAfterEmploymentEnd(): void
    {
        $decisiveDate = NempriDecisivePeriodResolver::decisiveDate('2026-07-02', '2026-06-28');

        self::assertSame('2026-06-29', $decisiveDate);
        self::assertSame(['2025-06-01', '2026-05-31'], NempriDecisivePeriodResolver::bounds($decisiveDate, '2019-01-01'));
        self::assertSame('2026-07-02', NempriDecisivePeriodResolver::decisiveDate('2026-07-02', null));
        self::assertSame('2026-06-20', NempriDecisivePeriodResolver::decisiveDate('2026-06-20', '2026-06-28'));
    }

    /**
     * NRO-03: krátké zaměstnání od 4. 5. 2026, skončení 28. 6., nemoc 2. 7.
     * Období je 4.–31. 5. (28 dnů), takže nastupuje pravděpodobný příjem.
     * S dnem události by vyšlo 58 dnů a pravděpodobný příjem by se nevyžádal.
     */
    public function testProtectionPeriodShortEmploymentNeedsProbableIncome(): void
    {
        $decisiveDate = NempriDecisivePeriodResolver::decisiveDate('2026-07-02', '2026-06-28');

        try {
            $this->resolver->resolve(
                $decisiveDate,
                '2026-05-04',
                '2026-01',
                NempriDecisiveSources::fromArrays([], [], [
                    '2026-05' => ['income_minor' => 2_800_000, 'excluded_days' => 0],
                    '2026-06' => ['income_minor' => 3_000_000, 'excluded_days' => 0],
                ]),
                null,
            );
            self::fail('Období kratší než 30 dnů vyžaduje pravděpodobný příjem.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_probable_income_missing', $exception->validationCode);
        }
    }

    /**
     * K1 (§ 18 odst. 6): celé období 10/2025–9/2026 v rodičovské bez příjmu.
     * Rozhodným obdobím je první předchozí kalendářní rok se započitatelným
     * příjmem a aspoň 30 dny — rok 2025 — ne pravděpodobný příjem.
     */
    public function testPeriodWithoutIncomeFallsBackToPreviousCalendarYear(): void
    {
        $months = [];
        for ($cursor = new \DateTimeImmutable('2025-01-01'); $cursor <= new \DateTimeImmutable('2026-09-01'); $cursor = $cursor->modify('+1 month')) {
            $period = $cursor->format('Y-m');
            $parental = $period >= '2025-10';
            $months[$period] = [
                'income_minor' => $parental ? 0 : 3_500_000,
                'excluded_days' => $parental ? (int) $cursor->format('t') : 0,
            ];
        }

        $result = $this->resolver->resolve(
            '2026-10-05',
            '2018-04-01',
            null,
            NempriDecisiveSources::fromArrays([], $months),
            null,
        );

        self::assertSame('2025-01-01', $result->from);
        self::assertSame('2025-12-31', $result->to);
        self::assertCount(12, $result->months);
        self::assertTrue($result->complete);
        self::assertNull($result->probableIncomeCzk);
        self::assertSame(9 * 3_500_000, $result->incomeMinor());
    }

    /** K1 (§ 19 odst. 8): žádný předchozí rok s příjmem → pravděpodobný příjem. */
    public function testNoPreviousYearWithIncomeUsesProbableIncome(): void
    {
        $months = [];
        for ($cursor = new \DateTimeImmutable('2025-01-01'); $cursor <= new \DateTimeImmutable('2026-09-01'); $cursor = $cursor->modify('+1 month')) {
            $months[$cursor->format('Y-m')] = ['income_minor' => 0, 'excluded_days' => (int) $cursor->format('t')];
        }

        $result = $this->resolver->resolve(
            '2026-10-05',
            '2025-01-01',
            null,
            NempriDecisiveSources::fromArrays([], $months),
            41_000,
        );

        self::assertSame('2025-10-01', $result->from);
        self::assertSame('2026-09-30', $result->to);
        self::assertSame([], $result->months);
        self::assertSame(41_000, $result->probableIncomeCzk);
    }

    /**
     * K1: zkrácené období podle § 18 odst. 4 (nástup 1. 3. 2026) bez příjmu
     * nepřechází na předchozí rok, ale na pravděpodobný příjem (§ 19 odst. 7).
     */
    public function testShortenedPeriodWithoutIncomeUsesProbableIncome(): void
    {
        $months = [];
        foreach (range(3, 9) as $month) {
            $months[sprintf('2026-%02d', $month)] = [
                'income_minor' => 0,
                'excluded_days' => (int) (new \DateTimeImmutable(sprintf('2026-%02d-01', $month)))->format('t'),
            ];
        }

        $result = $this->resolver->resolve(
            '2026-10-05',
            '2026-03-01',
            null,
            NempriDecisiveSources::fromArrays([], $months),
            39_000,
        );

        self::assertSame('2026-03-01', $result->from);
        self::assertSame(39_000, $result->probableIncomeCzk);
        self::assertSame([], $result->months);
    }

    /**
     * NRO-02: převzatý měsíc celý v neplaceném volnu. Hlášení nese 10357 = 0
     * a 10366 = 31; do NEMPRI patří 31 vyloučených dnů § 18 odst. 7, ne 0.
     * Druhý měsíc: 10357 = 4, 10366 = 19 → 19.
     */
    public function testTakeoverMonthUsesSection18ExcludedDays(): void
    {
        $takeover = [];
        for ($cursor = new \DateTimeImmutable('2025-03-01'); $cursor <= new \DateTimeImmutable('2026-02-01'); $cursor = $cursor->modify('+1 month')) {
            $period = $cursor->format('Y-m');
            $takeover[] = match ($period) {
                '2025-07' => $this->month($period, 0, 0, 0, sicknessExcludedDays: 31),
                '2025-08' => $this->month($period, 1_800_000, 4, 3_500_000, sicknessExcludedDays: 19),
                default => $this->month($period, 3_500_000, 0, 3_500_000, sicknessExcludedDays: 0),
            };
        }

        $result = $this->resolver->resolve(
            '2026-03-10',
            '2015-01-01',
            '2026-03',
            NempriDecisiveSources::fromArrays($takeover, []),
            null,
        );

        $byPeriod = [];
        foreach ($result->months as $month) {
            $byPeriod[$month->period()] = $month->excludedDays;
        }
        self::assertSame(31, $byPeriod['2025-07']);
        self::assertSame(19, $byPeriod['2025-08']);
        self::assertSame(50, $result->excludedDays());
    }

    /** NRO-02: měsíc bez příjmu a bez údaje § 18 odst. 7 se nesmí poslat s nulou. */
    public function testZeroIncomeMonthWithUnknownExcludedDaysStops(): void
    {
        $takeover = [];
        for ($cursor = new \DateTimeImmutable('2025-03-01'); $cursor <= new \DateTimeImmutable('2026-02-01'); $cursor = $cursor->modify('+1 month')) {
            $period = $cursor->format('Y-m');
            $takeover[] = $period === '2025-07'
                ? $this->month($period, 0, 0, 0)
                : $this->month($period, 3_500_000, 0, 3_500_000);
        }

        try {
            $this->resolver->resolve('2026-03-10', '2015-01-01', '2026-03', NempriDecisiveSources::fromArrays($takeover, []), null);
            self::fail('Neznámé vyloučené dny měsíce bez příjmu se nesmí nahradit nulou.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_decisive_excluded_days_unknown', $exception->validationCode);
            self::assertStringContainsString('2025-07', $exception->getMessage());
        }
    }

    /**
     * NRO-04 (§ 19 odst. 9): dohoda bez účasti má 10477 = 0 a 10476 = 9 000 Kč.
     * Započitatelný příjem je 9 000 Kč, ne nula. Běžný měsíc s 10476 = 10477
     * se nezdvojí.
     */
    public function testUninsuredAgreementIncomeIsCounted(): void
    {
        $takeover = [];
        for ($cursor = new \DateTimeImmutable('2025-03-01'); $cursor <= new \DateTimeImmutable('2026-02-01'); $cursor = $cursor->modify('+1 month')) {
            $period = $cursor->format('Y-m');
            $takeover[] = $period === '2025-05'
                ? $this->month($period, 0, 0, 900_000, uninsuredIncomeMinor: 900_000, participates: false, sicknessExcludedDays: 0)
                : $this->month($period, 3_000_000, 0, 3_000_000, uninsuredIncomeMinor: 3_000_000, sicknessExcludedDays: 0);
        }

        $result = $this->resolver->resolve('2026-03-10', '2015-01-01', '2026-03', NempriDecisiveSources::fromArrays($takeover, []), null);

        $byPeriod = [];
        foreach ($result->months as $month) {
            $byPeriod[$month->period()] = $month->countableIncomeMinor;
        }
        self::assertSame(900_000, $byPeriod['2025-05']);
        self::assertSame(3_000_000, $byPeriod['2025-06']);
    }

    /** NRO-05: haléře z ručního doplnění se zaokrouhlí na celé Kč nahoru. */
    public function testIncomeIsRoundedToWholeCrowns(): void
    {
        $result = $this->resolver->resolve(
            '2026-02-10',
            '2025-12-01',
            '2026-01',
            NempriDecisiveSources::fromArrays([], [
                '2025-12' => ['income_minor' => 2_560_645, 'excluded_days' => 0],
                '2026-01' => ['income_minor' => 2_560_600, 'excluded_days' => 0],
            ]),
            null,
        );

        self::assertSame(2_560_700, $result->months[0]->countableIncomeMinor);
        self::assertSame(2_560_600, $result->months[1]->countableIncomeMinor);
        self::assertSame(0, $result->incomeMinor() % 100);
    }

    /**
     * NRO-06 (§ 19 odst. 6): převedení 10. 3. 2026 kvůli těhotenství, nemoc
     * 2. 7. 2026. Před převedením vydělávala víc, takže výhodnější je období
     * ke dni převedení 3/2025–2/2026.
     */
    public function testTransferredEmployeeGetsTheMoreFavourablePeriod(): void
    {
        $months = [];
        for ($cursor = new \DateTimeImmutable('2025-03-01'); $cursor <= new \DateTimeImmutable('2026-06-01'); $cursor = $cursor->modify('+1 month')) {
            $period = $cursor->format('Y-m');
            $months[$period] = ['income_minor' => $period >= '2026-03' ? 2_000_000 : 4_000_000, 'excluded_days' => 0];
        }
        $sources = NempriDecisiveSources::fromArrays([], [], $months);

        $result = $this->resolver->resolve('2026-07-02', '2019-01-01', '2026-01', $sources, null, '2026-03-10');

        self::assertSame('2025-03-01', $result->from);
        self::assertSame('2026-02-28', $result->to);

        // Méně výhodné období ke dni převedení se nepoužije.
        $higherLater = [];
        foreach ($months as $period => $month) {
            $higherLater[$period] = ['income_minor' => $period >= '2026-03' ? 6_000_000 : 2_000_000, 'excluded_days' => 0];
        }
        $result = $this->resolver->resolve(
            '2026-07-02',
            '2019-01-01',
            '2026-01',
            NempriDecisiveSources::fromArrays([], [], $higherLater),
            null,
            '2026-03-10',
        );
        self::assertSame('2025-07-01', $result->from);
        self::assertSame('2026-06-30', $result->to);
    }

    /**
     * NRO-07 (§ 18 odst. 2 věta třetí): převzatý měsíc po dosažení ročního
     * maxima nese zastropovaný základ. Podání se zastaví; ruční měsíc za
     * totéž období projde.
     */
    public function testCappedTakeoverMonthStopsUntilEnteredManually(): void
    {
        $resolver = new NempriDecisivePeriodResolver(static fn (int $year): int => 10_000_000);
        $takeover = [];
        for ($cursor = new \DateTimeImmutable('2025-01-01'); $cursor <= new \DateTimeImmutable('2025-12-01'); $cursor = $cursor->modify('+1 month')) {
            $period = $cursor->format('Y-m');
            $base = $period <= '2025-02' ? 4_000_000 : ($period === '2025-03' ? 2_000_000 : 0);
            $takeover[] = $this->month($period, $base, 0, 4_000_000, sicknessExcludedDays: 0);
        }

        try {
            $resolver->resolve('2026-01-15', '2015-01-01', '2026-01', NempriDecisiveSources::fromArrays($takeover, []), null);
            self::fail('Měsíc krácený ročním maximem se nesmí poslat se zastropovaným základem.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_capped_base_unsupported', $exception->validationCode);
            self::assertStringContainsString('2025-03', $exception->getMessage());
        }

        $manual = [];
        foreach (range(3, 12) as $month) {
            $manual[sprintf('2025-%02d', $month)] = ['income_minor' => 4_000_000, 'excluded_days' => 0];
        }
        $result = $resolver->resolve('2026-01-15', '2015-01-01', '2026-01', NempriDecisiveSources::fromArrays($takeover, $manual), null);
        self::assertSame(12 * 4_000_000, $result->incomeMinor());
    }

    /** NRO-07: účastný měsíc se zúčtovaným příjmem a nulovým základem bez vyloučených dnů. */
    public function testTakeoverMonthWithIncomeButNoBaseStops(): void
    {
        $resolver = new NempriDecisivePeriodResolver(static fn (int $year): ?int => null);
        $takeover = [];
        for ($cursor = new \DateTimeImmutable('2025-01-01'); $cursor <= new \DateTimeImmutable('2025-12-01'); $cursor = $cursor->modify('+1 month')) {
            $period = $cursor->format('Y-m');
            $takeover[] = $this->month($period, $period === '2025-11' ? 0 : 5_000_000, 0, 5_000_000, sicknessExcludedDays: 0);
        }

        try {
            $resolver->resolve('2026-01-15', '2015-01-01', '2026-01', NempriDecisiveSources::fromArrays($takeover, []), null);
            self::fail('Převzatý měsíc s příjmem a nulovým základem se nesmí poslat s nulou.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_capped_base_unsupported', $exception->validationCode);
            self::assertStringContainsString('2025-11', $exception->getMessage());
        }
    }

    public function testPeriodTotalsComeFromMonths(): void
    {
        $period = new NempriDecisivePeriod('2025-10-01', '2025-11-30', [
            new NempriDecisiveMonth(2025, 10, 1_000_000, 0, NempriDecisiveMonth::SOURCE_TAKEOVER),
            new NempriDecisiveMonth(2025, 11, 1_100_000, 2, NempriDecisiveMonth::SOURCE_TAKEOVER),
        ], true);

        self::assertSame(2_100_000, $period->incomeMinor());
        self::assertSame(2, $period->excludedDays());
    }

    private function month(
        string $period,
        int $socialBaseMinor,
        int $excludedDays,
        int $grossMinor,
        ?int $sicknessExcludedDays = null,
        ?int $uninsuredIncomeMinor = null,
        bool $participates = true,
    ): PayrollTakeoverMonth {
        return PayrollTakeoverMonth::fromRow([
            'period' => $period . '-01',
            'source' => 'jmhz',
            'external_person_ref' => 'P1',
            'external_relationship_ref' => 'R1',
            'employee_id' => 1,
            'employment_id' => 7,
            'pension_participation' => $participates ? 1 : 0,
            'gross_minor' => $grossMinor,
            'social_base_minor' => $socialBaseMinor,
            'excluded_days' => $excludedDays,
            'sickness_excluded_days' => $sicknessExcludedDays,
            'uninsured_income_minor' => $uninsuredIncomeMinor,
        ]);
    }
}
