<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\TaxStatement;

/**
 * Roční podklad obou vyúčtování — dvanáct měsíců plus to, co patří do příloh.
 *
 * Vzniká výhradně z {@see \MyInvoice\Repository\Payroll\PayrollTaxStatementRepository},
 * tedy ze zmrazených zákonných výsledků schválených revizí a ze spárovaných
 * plateb. Druhý výpočet daně tu nevzniká — kdyby vznikl, rozešel by se
 * s odvodem, který si systém sám předepsal.
 */
final readonly class TaxStatementBasis
{
    /**
     * @param int $year Vykazované zdaňovací období.
     * @param list<TaxStatementMonth> $months Právě dvanáct řádků, leden až prosinec.
     * @param list<WorkplaceHeadcount> $workplaces Příloha č. 1 — počty osob podle
     *        místa výkonu práce k 1. prosinci.
     * @param int $nonResidentCount Kolik osob bylo v roce vedeno jako daňový
     *        nerezident. Nenulová hodnota znamená povinnou přílohu č. 2, kterou
     *        aplikace neumí naplnit — viz {@see TaxStatementCalculator}.
     * @param list<string> $warnings
     * @param list<string> $blockers Důvody, proč tiskopis vzniknout NESMÍ — typicky
     *        převzatý měsíc, ve kterém trval vztah, ale počáteční stav za něj chybí.
     *        Náhled je ukáže, XML se nesestaví.
     * @param list<int> $monthsBeforeStart Měsíce roku před začátkem vedení mezd
     *        v MyÚčtu. Za ty se neschvaluje běh — jejich podklad jsou převzaté
     *        počáteční stavy, takže výzva „schvalte běh" by u nich nedávala smysl.
     */
    public function __construct(
        public int $year,
        public array $months,
        public array $workplaces,
        public int $nonResidentCount,
        public array $warnings = [],
        public array $blockers = [],
        public array $monthsBeforeStart = [],
        /**
         * Komu chybí převzaté úhrny — jen pro náhled, aby šlo proklikem otevřít
         * kartu toho, u koho se to doplňuje. Do tiskopisu nejde.
         *
         * @var list<array{employee_id:int,employee_name:string,missing_months:list<int>}>
         */
        public array $takeoverGaps = [],
    ) {
        if (count($months) !== 12) {
            throw new \LogicException('Podklad vyúčtování musí mít dvanáct měsíců.');
        }
        foreach ($months as $index => $month) {
            if ($month->month !== $index + 1) {
                throw new \LogicException(
                    'Měsíce podkladu vyúčtování nejsou v pořadí leden až prosinec.',
                );
            }
        }
    }

    public function hasApprovedRun(): bool
    {
        foreach ($this->months as $month) {
            if ($month->hasSource()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> měsíce, jejichž úhrny jsou z převzatých počátečních stavů */
    public function takenOverMonths(): array
    {
        $months = [];
        foreach ($this->months as $month) {
            if ($month->takenOver) {
                $months[] = $month->month;
            }
        }

        return $months;
    }
}
