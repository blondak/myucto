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
 *
 * Výjimkou je DÉLKA VYROVNÁVACÍHO OBDOBÍ. Ta zákonná konstanta není: § 93
 * odst. 4 dovoluje nejvýše 26 týdnů po sobě jdoucích a delší období (nejvýše
 * 52 týdnů) smí vymezit „jen kolektivní smlouva". Je to tedy údaj konkrétního
 * zaměstnavatele podmíněný důkazem, a proto se čte z tabulky
 * `payroll_overtime_averaging_periods`. `$averagingBasis` a
 * `$averagingReference` drží, o co se délka opírá, aby odpověď API i obrazovka
 * docházky uměly říct proč.
 */
final readonly class OvertimeLimits
{
    public const BASIS_STATUTORY = 'statutory';
    public const BASIS_COLLECTIVE_AGREEMENT = 'collective_agreement';

    public const BASES = [self::BASIS_STATUTORY, self::BASIS_COLLECTIVE_AGREEMENT];

    public function __construct(
        public int $orderedWeeklyMaxMinutes,
        public int $orderedYearlyMaxMinutes,
        public int $averagingWeeklyMaxMinutes,
        public int $averagingMaxWeeks,
        public int $annualEarlyWarningBasisPoints,
        public bool $fromRuleset,
        public string $averagingBasis = self::BASIS_STATUTORY,
        public ?string $averagingReference = null,
    ) {
        if ($orderedWeeklyMaxMinutes <= 0
            || $orderedYearlyMaxMinutes <= 0
            || $averagingWeeklyMaxMinutes <= 0
        ) {
            throw new \InvalidArgumentException('Limity přesčasu musí být kladné.');
        }
        if (!in_array($averagingBasis, self::BASES, true)) {
            throw new \InvalidArgumentException(
                'Vyrovnávací období se smí opírat jen o zákon nebo o kolektivní smlouvu.',
            );
        }
        // § 93 odst. 4 — nad 26 týdnů se smí jít jen kolektivní smlouvou, a to
        // nejvýše na 52 týdnů. Nedoložené prodloužení je proto vyloučené už
        // v konstruktoru, ne až v uživatelské hlášce.
        $maximum = $averagingBasis === self::BASIS_COLLECTIVE_AGREEMENT ? 52 : 26;
        if ($averagingMaxWeeks < 1 || $averagingMaxWeeks > $maximum) {
            throw new \InvalidArgumentException(sprintf(
                'Vyrovnávací období přesčasu musí být 1 až %d týdnů.',
                $maximum,
            ));
        }
        if ($averagingBasis === self::BASIS_COLLECTIVE_AGREEMENT
            && ($averagingReference === null || trim($averagingReference) === '')
        ) {
            throw new \InvalidArgumentException(
                'Vyrovnávací období delší než zákonné musí odkazovat na kolektivní smlouvu.',
            );
        }
        if ($annualEarlyWarningBasisPoints < 0 || $annualEarlyWarningBasisPoints > 10_000) {
            throw new \InvalidArgumentException(
                'Práh včasného upozornění musí být 0 až 10000 bazických bodů.',
            );
        }
    }

    public function withAveragingPeriod(
        int $weeks,
        string $basis,
        ?string $reference,
    ): self {
        return new self(
            $this->orderedWeeklyMaxMinutes,
            $this->orderedYearlyMaxMinutes,
            $this->averagingWeeklyMaxMinutes,
            $weeks,
            $this->annualEarlyWarningBasisPoints,
            $this->fromRuleset,
            $basis,
            $reference,
        );
    }
}
