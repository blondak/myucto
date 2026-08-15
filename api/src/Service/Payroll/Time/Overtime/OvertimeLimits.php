<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Overtime;

/**
 * Zákonné stropy přesčasové práce podle § 93 zákoníku práce.
 *
 * Hodnoty PATŘÍ DO RULESETU, ne do konstant v kódu — viz
 * {@see OvertimeLimitRules}, které je odtud čte. Tahle třída je jen jejich
 * přenoska; `$fromRuleset` říká, jestli se povedlo hodnoty vzít z rulesetu,
 * nebo jestli se sáhlo po zákonném výchozím nastavení.
 */
final readonly class OvertimeLimits
{
    public function __construct(
        public int $orderedWeeklyMaxMinutes,
        public int $orderedYearlyMaxMinutes,
        public int $averagingWeeklyMaxMinutes,
        public int $averagingMaxWeeks,
        public int $annualEarlyWarningBasisPoints,
        public bool $fromRuleset,
    ) {
        if ($orderedWeeklyMaxMinutes <= 0
            || $orderedYearlyMaxMinutes <= 0
            || $averagingWeeklyMaxMinutes <= 0
        ) {
            throw new \InvalidArgumentException('Limity přesčasu musí být kladné.');
        }
        if ($averagingMaxWeeks < 1 || $averagingMaxWeeks > 52) {
            throw new \InvalidArgumentException(
                'Vyrovnávací období přesčasu musí být 1 až 52 týdnů.',
            );
        }
        if ($annualEarlyWarningBasisPoints < 0 || $annualEarlyWarningBasisPoints > 10_000) {
            throw new \InvalidArgumentException(
                'Práh včasného upozornění musí být 0 až 10000 bazických bodů.',
            );
        }
    }
}
