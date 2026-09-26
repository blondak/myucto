<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use InvalidArgumentException;

/**
 * Jeden násobek průměrného výdělku, ze kterého bylo odvozeno odstupné.
 *
 * § 299 odst. 4 věta první o. s. ř.: „Z odstupného se srážky vypočítávají
 * zvlášť z každého násobku průměrného výdělku … ze kterých byla odvozena výše
 * nebo minimální výše odstupného." Každý násobek je tedy samostatný měsíční
 * příjem s vlastní nezabavitelnou částkou a vlastními třetinami; odstupné
 * 3× průměr se nesráží jako jedna trojnásobná mzda.
 *
 * `index` je pořadí měsíce „doby poskytování odstupného" počítané ode dne po
 * skončení (1 = první měsíc po skončení). Stejný index u dvou plnění (odstupné
 * a jednorázová náhrada § 271ca ZP, obě „odstupné" ve smyslu § 299 odst. 1
 * písm. g)) patří do téhož měsíce, takže se sčítají.
 *
 * `otherIncomeOverlap` říká, že povinný v tomto měsíci už pracuje u jiného
 * plátce nebo má jiný příjem podle § 299 odst. 1 až 3 (věta druhá § 299
 * odst. 4): násobek je pak měsíčním příjmem VEDLE toho druhého a samostatná
 * nezabavitelná částka mu nenáleží. Bez potvrzení, že nezabavitelnou částku
 * za ten měsíc započítává druhý plátce (`otherPayerAppliesProtectedAmount`),
 * se výpočet zastaví — plátce odstupného druhý příjem nezná a odhadovat ho
 * nesmí.
 */
final readonly class SeveranceMultiple
{
    public const MAX_MULTIPLES = 36;

    public function __construct(
        public int $index,
        public int $amountMinorUnits,
        public bool $otherIncomeOverlap = false,
        public bool $otherPayerAppliesProtectedAmount = false,
    ) {
        if ($index < 1 || $index > self::MAX_MULTIPLES) {
            throw new InvalidArgumentException('Pořadí násobku odstupného je mimo rozsah.');
        }
        if ($amountMinorUnits < 0) {
            throw new InvalidArgumentException('Násobek odstupného nesmí být záporný.');
        }
    }

    /**
     * Rozdělí čistou částku odstupného na `$count` stejných násobků. Haléřový
     * zbytek dělení dostanou první násobky po jednom haléři, takže součet
     * násobků je vždy přesně celá částka.
     *
     * @return list<int>
     */
    public static function split(int $amountMinorUnits, int $count): array
    {
        if ($count < 1 || $count > self::MAX_MULTIPLES) {
            throw new InvalidArgumentException('Počet násobků odstupného je mimo rozsah.');
        }
        if ($amountMinorUnits < 0) {
            throw new InvalidArgumentException('Odstupné nesmí být záporné.');
        }
        $base = intdiv($amountMinorUnits, $count);
        $remainder = $amountMinorUnits - $base * $count;
        $parts = [];
        for ($index = 0; $index < $count; $index++) {
            $parts[] = $base + ($index < $remainder ? 1 : 0);
        }

        return $parts;
    }

    /**
     * Poslední den měsíce doby poskytování odstupného, do kterého patří
     * násobek `$index` (§ 299 odst. 4 věta druhá o. s. ř.). Doba běží ode dne
     * po skončení: skončení 30. 6. → 1. násobek do 31. 7.; skončení 15. 6.
     * → 1. násobek 16. 6. až 15. 7.
     */
    public static function periodEnd(string $employmentEndDate, int $index): string
    {
        $start = (new \DateTimeImmutable($employmentEndDate))->modify('+1 day');
        $month = (int) $start->format('n') + $index;
        $year = (int) $start->format('Y') + intdiv($month - 1, 12);
        $month = ($month - 1) % 12 + 1;
        $lastDay = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');

        return (new \DateTimeImmutable(sprintf(
            '%04d-%02d-%02d',
            $year,
            $month,
            min((int) $start->format('j'), $lastDay),
        )))->modify('-1 day')->format('Y-m-d');
    }

    /** @return array{amount_minor_units:int,index:int,other_income_overlap:bool,other_payer_applies_protected_amount:bool} */
    public function toCanonicalArray(): array
    {
        return [
            'amount_minor_units' => $this->amountMinorUnits,
            'index' => $this->index,
            'other_income_overlap' => $this->otherIncomeOverlap,
            'other_payer_applies_protected_amount' => $this->otherPayerAppliesProtectedAmount,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromCanonicalArray(array $data): self
    {
        $index = $data['index'] ?? null;
        $amount = $data['amount_minor_units'] ?? null;
        $overlap = $data['other_income_overlap'] ?? null;
        $otherPayer = $data['other_payer_applies_protected_amount'] ?? null;
        if (!is_int($index) || !is_int($amount) || !is_bool($overlap) || !is_bool($otherPayer)) {
            throw new InvalidArgumentException('Násobek odstupného ve snímku není platný.');
        }

        return new self($index, $amount, $overlap, $otherPayer);
    }
}
