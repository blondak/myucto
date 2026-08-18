<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Insurance;

use MyInvoice\Service\Payroll\Calculation\Money;

/**
 * MZ-11 — rozdělení pojistného zaměstnavatele na sociální mezi osoby.
 *
 * Pojistné zaměstnavatele NENÍ osobní veličina: § 5a odst. 1 zákona č. 589/1992
 * Sb. říká, že vyměřovacím základem zaměstnavatele je „částka odpovídající úhrnu
 * vyměřovacích základů jeho zaměstnanců", a § 7 odst. 3 zaokrouhluje až tenhle
 * jeden součet. Přepočet po osobách by proto dal jiný úhrn než zákonná částka.
 *
 * Cokoli per osobu je tedy ROZDĚLENÍ firemního čísla, ne zákonná částka — a musí
 * se tak i jmenovat všude, kam doteče. Metoda je poměr vyměřovacích základů;
 * zbytek po celočíselném dělení dostane největší zbytek, při shodě nižší
 * `employee_id`, takže výsledek nezávisí na pořadí a součet sedí na korunu.
 *
 * Sleva za částečné úvazky (§ 7a) se rozděluje ZVLÁŠŤ a jen mezi vztahy, které
 * ji doloženě uplatnily — rozpustit ji poměrem všech základů by ji přiznala
 * i lidem, kterým nenáleží.
 *
 * Třída vznikla vyjmutím počítající části z {@see \MyInvoice\Service\Payroll\Document\PayslipDocumentSnapshotMapper},
 * který rozdělení dělal jako jediný. Rozklad pojistného ho počítat musí taky
 * a dvě nezávislé implementace téhož rozdělení by se dřív nebo později rozešly.
 */
final class EmployerSocialInsuranceAllocation
{
    public const METHOD = 'capped_assessment_base_share';
    public const RESIDUAL_RULE = 'largest_remainder';

    /**
     * @param array<int,int> $cappedBases   employee_id → vyměřovací základ osoby po stropu
     * @param array<int,int> $discountBases employee_id → základ vztahů s doloženou slevou
     * @return array<int,int> employee_id → pojistné zaměstnavatele po slevě
     */
    public static function allocate(
        array $cappedBases,
        array $discountBases,
        int $beforeDiscount,
        int $discount,
    ): array {
        if (array_keys($cappedBases) !== array_keys($discountBases)) {
            throw new \InvalidArgumentException(
                'Rozdělení pojistného zaměstnavatele potřebuje obě váhy pro tytéž osoby.',
            );
        }
        $beforeAllocations = self::allocateByWeights(
            $cappedBases,
            $beforeDiscount,
            'pojistné zaměstnavatele',
        );
        $discountAllocations = self::allocateByWeights(
            $discountBases,
            $discount,
            'slevu zaměstnavatele',
        );
        $allocations = [];
        foreach ($beforeAllocations as $employeeId => $amount) {
            $allocated = $amount - $discountAllocations[$employeeId];
            if ($allocated < 0) {
                throw new \DomainException(
                    'Sleva zaměstnavatele převyšuje pojistné konkrétní osoby.',
                );
            }
            $allocations[$employeeId] = $allocated;
        }

        return $allocations;
    }

    /**
     * @param array<int,int> $weights
     * @return array<int,int>
     */
    public static function allocateByWeights(array $weights, int $total, string $context): array
    {
        $weightTotal = new Money(0);
        foreach ($weights as $weight) {
            $weightTotal = $weightTotal->add(new Money($weight));
        }
        if ($total > 0 && $weightTotal->minorUnits === 0) {
            throw new \DomainException(
                ucfirst($context) . ' nelze rozdělit bez příslušných vyměřovacích základů.',
            );
        }
        if ($weightTotal->minorUnits === 0) {
            return array_fill_keys(array_keys($weights), 0);
        }

        $allocations = [];
        $minorRemainders = [];
        $assigned = 0;
        foreach ($weights as $employeeId => $weight) {
            [$quotient, $remainder] = self::multiplyDivide(
                $total,
                $weight,
                $weightTotal->minorUnits,
            );
            $allocations[$employeeId] = $quotient;
            $minorRemainders[$employeeId] = $remainder;
            $assigned += $allocations[$employeeId];
        }
        self::distributeRemainder($allocations, $total - $assigned, $minorRemainders);
        ksort($allocations, SORT_NUMERIC);

        return $allocations;
    }

    /** @return array{0:int,1:int} */
    private static function multiplyDivide(int $amount, int $weight, int $totalWeight): array
    {
        if ($amount < 0 || $weight < 0 || $totalWeight <= 0 || $weight > $totalWeight) {
            throw new \InvalidArgumentException(
                'Poměrné rozdělení pojistného nemá platné hodnoty.',
            );
        }
        $quotient = 0;
        $remainder = 0;
        $partQuotient = intdiv($amount, $totalWeight);
        $partRemainder = $amount % $totalWeight;
        $multiplier = $weight;
        while ($multiplier > 0) {
            if (($multiplier % 2) === 1) {
                [$quotient, $remainder] = self::addDivisionParts(
                    $quotient,
                    $remainder,
                    $partQuotient,
                    $partRemainder,
                    $totalWeight,
                );
            }
            $multiplier = intdiv($multiplier, 2);
            if ($multiplier > 0) {
                [$partQuotient, $partRemainder] = self::addDivisionParts(
                    $partQuotient,
                    $partRemainder,
                    $partQuotient,
                    $partRemainder,
                    $totalWeight,
                );
            }
        }

        return [$quotient, $remainder];
    }

    /** @return array{0:int,1:int} */
    private static function addDivisionParts(
        int $leftQuotient,
        int $leftRemainder,
        int $rightQuotient,
        int $rightRemainder,
        int $denominator,
    ): array {
        $boundary = $denominator - $rightRemainder;
        if ($leftRemainder >= $boundary) {
            $remainder = $leftRemainder - $boundary;
            $carry = 1;
        } else {
            $remainder = $leftRemainder + $rightRemainder;
            $carry = 0;
        }
        $quotient = self::addBounded(self::addBounded($leftQuotient, $rightQuotient), $carry);

        return [$quotient, $remainder];
    }

    private static function addBounded(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $right > PHP_INT_MAX - $left) {
            throw new \OverflowException('Poměrné rozdělení pojistného přeteklo.');
        }

        return $left + $right;
    }

    /**
     * @param array<int,int> $values
     * @param array<int,int> $remainders
     */
    private static function distributeRemainder(
        array &$values,
        int $remaining,
        array $remainders,
    ): void {
        if ($remaining < 0 || $remaining > count($values)) {
            throw new \LogicException('Poměrné rozdělení má neplatný zbytek.');
        }
        $ids = array_keys($values);
        usort($ids, static fn (int $left, int $right): int =>
            ($remainders[$right] <=> $remainders[$left])
            ?: ($left <=> $right));
        for ($index = 0; $index < $remaining; ++$index) {
            $values[$ids[$index]]++;
        }
    }
}
