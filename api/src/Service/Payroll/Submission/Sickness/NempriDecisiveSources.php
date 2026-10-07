<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use MyInvoice\Service\Payroll\Migration\PayrollTakeoverMonth;

/**
 * Podklady měsíců rozhodného období jednoho pracovního vztahu.
 *
 * Tři zdroje v pořadí přednosti:
 *
 *  1. ruční doplnění u případu dávky (`$manual`),
 *  2. schválené mzdové běhy MyÚčta ({@see NempriPayrollMonthReader}) — tentýž
 *     zdroj, ze kterého vzniká jednotné měsíční hlášení,
 *  3. převzaté mzdy předchozího programu ({@see PayrollTakeoverMonth}).
 *
 * Roky se načítají až na vyžádání: rozhodné období podle § 18 odst. 6
 * zák. č. 187/2006 Sb. se hledá postupně v předchozích kalendářních letech
 * a dopředu se neví, kolik jich bude potřeba.
 */
final class NempriDecisiveSources
{
    /** @var array<int,list<PayrollTakeoverMonth>> */
    private array $takeoverByYear = [];

    /** @var array<int,array<string,array{income_minor:?int,excluded_days:?int}>> */
    private array $payrollByYear = [];

    /**
     * @param \Closure(int):list<PayrollTakeoverMonth> $takeover převzaté měsíce vztahu za rok
     * @param \Closure(int):array<string,array{income_minor:?int,excluded_days:?int}> $payroll
     *        měsíce ze schválených mzdových běhů za rok, klíčované `YYYY-MM`
     * @param array<string,array{income_minor:int,excluded_days:int}> $manual
     *        ruční doplnění klíčované `YYYY-MM`
     */
    public function __construct(
        private readonly \Closure $takeover,
        private readonly \Closure $payroll,
        private readonly array $manual,
    ) {}

    /**
     * @param list<PayrollTakeoverMonth> $takeoverMonths
     * @param array<string,array{income_minor:int,excluded_days:int}> $manualMonths
     * @param array<string,array{income_minor:?int,excluded_days:?int}> $payrollMonths
     */
    public static function fromArrays(
        array $takeoverMonths,
        array $manualMonths,
        array $payrollMonths = [],
    ): self {
        return new self(
            static fn (int $year): array => array_values(array_filter(
                $takeoverMonths,
                static fn (PayrollTakeoverMonth $month): bool => (int) substr($month->period, 0, 4) === $year,
            )),
            static fn (int $year): array => array_filter(
                $payrollMonths,
                static fn (string $period): bool => (int) substr($period, 0, 4) === $year,
                ARRAY_FILTER_USE_KEY,
            ),
            $manualMonths,
        );
    }

    /** @return array{income_minor:int,excluded_days:int}|null */
    public function manual(string $period): ?array
    {
        return $this->manual[$period] ?? null;
    }

    /** @return array{income_minor:?int,excluded_days:?int}|null */
    public function payroll(string $period): ?array
    {
        $year = (int) substr($period, 0, 4);
        $this->payrollByYear[$year] ??= ($this->payroll)($year);

        return $this->payrollByYear[$year][$period] ?? null;
    }

    /** @return list<PayrollTakeoverMonth> převzaté řádky téhož měsíce */
    public function takeover(string $period): array
    {
        return array_values(array_filter(
            $this->takeoverYear((int) substr($period, 0, 4)),
            static fn (PayrollTakeoverMonth $month): bool => $month->period === $period,
        ));
    }

    /** @return list<PayrollTakeoverMonth> */
    public function takeoverYear(int $year): array
    {
        return $this->takeoverByYear[$year] ??= ($this->takeover)($year);
    }
}
