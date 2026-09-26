<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Jeden měsíc rozhodného období (`CtPolozkaRozhodnehoObdobi`).
 *
 * Příjem je v haléřích; do věty jde v Kč. `source` říká, odkud měsíc je
 * (`takeover` = převzaté mzdy, `manual` = ruční doplnění u případu).
 */
final readonly class NempriDecisiveMonth
{
    public const SOURCE_TAKEOVER = 'takeover';
    public const SOURCE_MANUAL = 'manual';

    public function __construct(
        public int $year,
        public int $month,
        public int $countableIncomeMinor,
        public int $excludedDays,
        public string $source,
    ) {}

    public function period(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }
}
