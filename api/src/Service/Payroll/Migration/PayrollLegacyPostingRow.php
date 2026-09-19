<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

/**
 * Jeden AGREGOVANÝ řádek převzatého zaúčtování: plnění → účet MD, účet D,
 * případně středisko.
 *
 * Tohle je obecná struktura, kterou naplní kterýkoliv zdroj. Není v ní nic z
 * PAMICY ani z jiného programu: kdo to přečetl, drží jen `sourceKey` zdrojové
 * čtečky a `sourceReference` konkrétního řádku pro dohledání.
 *
 * **Řádek je už sečtený.** Původní program jich má desítky tisíc (jeden na
 * složku × mzdu × měsíc) a jednotlivě nás nezajímají: pro odvození předkontace
 * je podstatné, KOLIK řádků a KOLIK peněz za daným účtem stojí. Sčítá proto už
 * čtečka, podle své vlastní přirozené jednotky (u PAMICY předkontace + složka).
 *
 * `amountMinor` je v haléřích a je to ABSOLUTNÍ hodnota: převzaté programy píší
 * storna a opravy záporem a součet se znaménkem by u opravné složky vyšel skoro
 * nula, takže by váha řádku zmizela právě tam, kde jsou peníze.
 */
final readonly class PayrollLegacyPostingRow
{
    /**
     * @param ?string $concept význam z {@see PayrollLegacyPostingConcepts::CONCEPTS};
     *        `null` = zdroj význam nerozpoznal a řádek jde jen do přehledu
     * @param string $label popis plnění tak, jak ho nese původní program
     * @param ?string $debitAccount účet MD převedený do tvaru osnovy MyÚčta
     * @param ?string $creditAccount účet D převedený do tvaru osnovy MyÚčta
     * @param ?string $debitSourceCode účet MD v původním tvaru (`521000`)
     * @param ?string $creditSourceCode účet D v původním tvaru
     * @param list<string> $costCenters střediska, na která řádek šel
     */
    public function __construct(
        public string $sourceKey,
        public string $sourceReference,
        public ?string $concept,
        public string $label,
        public ?string $debitAccount,
        public ?string $creditAccount,
        public ?string $debitSourceCode,
        public ?string $creditSourceCode,
        public int $lineCount,
        public int $amountMinor,
        public array $costCenters = [],
    ) {
        if ($sourceKey === '') {
            throw new \InvalidArgumentException('Převzaté zaúčtování musí nést označení zdroje.');
        }
        if ($concept !== null && !PayrollLegacyPostingConcepts::isKnown($concept)) {
            throw new \InvalidArgumentException("Neznámý mzdový význam převzatého zaúčtování: {$concept}.");
        }
        if ($lineCount < 0 || $amountMinor < 0) {
            throw new \InvalidArgumentException('Počet řádků ani částka převzatého zaúčtování nesmí být záporné.');
        }
    }

    /** Účet strany, nebo `null`, když ji řádek nemá. */
    public function account(string $side): ?string
    {
        return $side === 'debit' ? $this->debitAccount : $this->creditAccount;
    }

    /** Účet strany v původním tvaru. */
    public function sourceCode(string $side): ?string
    {
        return $side === 'debit' ? $this->debitSourceCode : $this->creditSourceCode;
    }
}
