<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;

/**
 * Druhý zdroj ročních dokladů § 38j — počáteční stavy kumulací.
 *
 * Roční zúčtování čte opening i schválené běhy jednou cestou
 * ({@see PayrollStatutoryAccumulatorRepository::stateForYear()}); roční doklady
 * to donedávna neuměly a stavěly se výhradně ze schválených revizí. Tahle
 * třída jim ten druhý zdroj dodává ze STEJNÉ tabulky a stejného repozitáře.
 *
 * Proč `openingBalance()` a ne `stateForYear()`:
 *
 *  * Doklad si běhovou část skládá ze zmrazených revizí, jejichž otisky
 *    ověřuje — kdyby ji vzal ze součtu `stateForYear()`, ztratil by vazbu na
 *    zdroj a nešlo by ji doložit. Potřebuje tedy JEN větev `opening_balance`,
 *    kterou `openingBalance()` vrací napřímo.
 *  * `stateForYear()` na chybějící opening vyhodí výjimku. Rozhodnutí, jestli
 *    je chybějící opening mezera (přechod uprostřed roku) nebo ne (firma vede
 *    mzdy od ledna), musí padnout tady, ne v repozitáři.
 *  * `stateForYear()` validuje druh kumulace proti pevnému seznamu polí, takže
 *    pro `health_insurance` dnes skončí chybou. `openingBalance()` druh
 *    nevaliduje, takže zdravotní pojištění se převezme samo, jakmile ho začne
 *    někdo zapisovat — což je požadavek na dopřednou kompatibilitu.
 */
final readonly class PayrollCarriedOverPeriodReader
{
    public function __construct(
        private PayrollStatutoryAccumulatorRepository $accumulators,
        private PayrollHistoricalPeriodService $historicalPeriods,
    ) {}

    /**
     * Rozhoduje ZAČÁTEK VEDENÍ MEZD, ne první měsíc se schválenou revizí té osoby.
     *
     * Jsou to dvě různé věci a záměna by odmítla doklad každému, kdo prostě nastoupil
     * později v roce: u něj za dřívější měsíce není co převzít, protože v nich žádný
     * příjem neměl. Převzít je co jen za měsíce, které MyÚčto nepočítalo NIKOMU, a to
     * říká `payroll_module_state.start_period` ({@see PayrollHistoricalPeriodService}).
     *
     * @param int $firstProcessedMonth první měsíc roku se schválenou revizí té osoby
     */
    public function read(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        int $firstProcessedMonth,
    ): ?PayrollCarriedOverPeriod {
        $carryStart = self::expectedCarryStart(
            $this->historicalPeriods->startPeriod($supplierId),
            $taxYear,
            $firstProcessedMonth,
        );
        $openings = [];
        foreach (PayrollCarriedOverPeriod::KINDS as $kind) {
            $openings[$kind] = $this->accumulators->openingBalance(
                $supplierId,
                $employeeId,
                $taxYear,
                $kind,
            );
        }

        return PayrollCarriedOverPeriod::fromOpenings(
            $openings,
            $carryStart,
        );
    }

    /**
     * Od kterého měsíce roku už mzdy počítá MyÚčto, tedy odkud dál se převzatý počáteční
     * stav nečeká. Vrací 1, když není co převzít.
     *
     * Veřejné a statické schválně: je to celé pravidlo a musí jít zavolat i z testu,
     * jinak by se okopírovalo (viz AGENTS.md o skrytých pomocnících).
     *
     * @param ?string $startPeriod `payroll_module_state.start_period` firmy (`YYYY-MM-DD`)
     * @param int $firstProcessedMonth první měsíc roku se schválenou revizí té osoby
     */
    public static function expectedCarryStart(?string $startPeriod, int $taxYear, int $firstProcessedMonth): int
    {
        if ($startPeriod === null || preg_match('/^(\d{4})-(\d{2})/D', $startPeriod, $m) !== 1) {
            return 1;
        }
        // Vedení mezd začalo v dřívějším roce: celý tenhle rok počítalo MyÚčto.
        // Začátek v pozdějším roce sem vůbec nepatří, doklad by za ten rok nevznikl.
        if ((int) $m[1] !== $taxYear) {
            return 1;
        }
        $carryStart = (int) $m[2];
        // Osoba, jejíž první spočítaný měsíc je až PO začátku vedení mezd, u tohohle
        // zaměstnavatele dřív nepracovala: měsíce mezi začátkem vedení mezd a jejím
        // nástupem MyÚčto počítalo ostatním, jí ne. Není tedy co převzít.
        return $firstProcessedMonth > $carryStart ? 1 : $carryStart;
    }
}
