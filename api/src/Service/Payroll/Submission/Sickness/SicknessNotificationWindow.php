<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Okno, ve kterém má být podání PŘEDÁNO územní správě sociálního zabezpečení.
 *
 * Obě hranice jsou hranicemi PŘEDÁNÍ, ne přípravy — § 97 odst. 1 a 2
 * zák. č. 187/2006 Sb. mluví o předávání a zasílání, ne o vyhotovení.
 *
 * `sourceStatus` je tu navíc oproti ostatním agendám a je to ta nejdůležitější
 * položka:
 *
 * * `statute_verified` — zákon stanoví den. Platí jen u vyrovnávacího
 *   příspěvku (§ 97 odst. 5: „nejpozději v následující pracovní den po dni,
 *   který je určen pro výplatu mezd a platů").
 * * `derived_immediacy` — zákon říká „neprodleně" a žádný počet dnů
 *   nestanoví. Termín je pak PRVNÍ den, kdy se povinnost splnit dá; víc než to
 *   z textu vyčíst nelze a vymýšlet si osmidenní lhůtu, která tam není, by
 *   znamenalo tvrdit, že opoždění o týden je v pořádku.
 */
final readonly class SicknessNotificationWindow
{
    public function __construct(
        public string $earliestNotificationOn,
        public string $dueOn,
        public string $calendarBasis,
        public string $rulesetId,
        public string $rulesetHash,
        public string $legalReference,
        public string $sourceStatus,
    ) {}

    /**
     * Kalendář lhůty v registru povinností (`calendar_days` / `business_days`).
     *
     * `calendarBasis` tady nese PRAVIDLO, podle kterého se termín spočítal
     * („immediately“, „next_working_day_after_payday“). Registr povinností
     * přijímá jen druh kalendáře a pravidlo odmítl, takže „Připravit NEMPRI“
     * spadlo dřív, než vzniklo podání. Obě pravidla končí termínem na nejbližší
     * pracovní den podle zák. č. 245/2000 Sb., tedy v pracovních dnech.
     */
    public function obligationCalendarBasis(): string
    {
        return 'business_days';
    }
}
