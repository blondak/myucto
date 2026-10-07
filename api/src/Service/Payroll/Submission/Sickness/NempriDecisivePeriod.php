<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * `CtRozhodneObdobi` — rozhodné období do NEMPRI.
 *
 * Dvě podoby, nikdy obě naráz (logické kontroly NEMPRI25 č. 7, 8 a 16):
 *
 *  - **úplný seznam měsíců** (`$complete = true`): každý měsíc rozhodného
 *    období se započitatelným příjmem v celých Kč a vyloučenými dny; věta
 *    k nim nese i oba součty,
 *  - **pravděpodobná výše příjmu** (`$probableIncomeCzk`): jen hranice
 *    období, `$months = []`, `$complete = false`.
 */
final readonly class NempriDecisivePeriod
{
    /**
     * @param list<NempriDecisiveMonth> $months
     */
    public function __construct(
        public string $from,
        public string $to,
        public array $months,
        public bool $complete,
        public ?int $probableIncomeCzk = null,
    ) {}

    /** Započitatelný příjem celého období v haléřích. */
    public function incomeMinor(): int
    {
        return array_sum(array_map(
            static fn (NempriDecisiveMonth $month): int => $month->countableIncomeMinor,
            $this->months,
        ));
    }

    /** Vyloučené dny celého období. */
    public function excludedDays(): int
    {
        return array_sum(array_map(
            static fn (NempriDecisiveMonth $month): int => $month->excludedDays,
            $this->months,
        ));
    }
}
