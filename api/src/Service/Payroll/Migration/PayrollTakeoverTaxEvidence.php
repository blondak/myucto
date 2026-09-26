<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

/**
 * Soulad převzatých měsíců s evidencí nároků — pravidlo sdílené ročním
 * zúčtováním a potvrzením o zdanitelných příjmech.
 *
 * Počáteční stav nese, KOLIK se v převzatém měsíci uplatnilo (sleva na dítě,
 * bonus), ale ne NA KOHO. Roční zúčtování i potvrzení § 38j proto berou nárok
 * z evidence dětí v MyÚčtu. U firmy, která zadala dítě až od měsíce, kdy začala
 * vést mzdy, pak evidence tvrdí, že v lednu až září nárok nebyl — a roční
 * zúčtování by zvýhodnění, které zaměstnanec u předchozího programu dostal,
 * vyrovnalo jako neoprávněné, potvrzení by ty měsíce u dítěte vynechalo.
 *
 * Tahle třída to zachytí dřív, než z toho vznikne doklad: měsíc, ve kterém
 * převzatý stav uplatnil zvýhodnění na dítě, musí mít v evidenci aspoň jedno
 * uplatněné dítě. Náprava je zpětné zadání nároku od prvního měsíce, ve kterém
 * ho zaměstnanec uplatňoval.
 */
final class PayrollTakeoverTaxEvidence
{
    /**
     * Převzaté měsíce, ve kterých se uplatnilo zvýhodnění na dítě, ale evidence
     * dětí na ně nárok nemá.
     *
     * @param array<int,array<string,int>> $openingMonths měsíc => řádek rozpisu počátečního stavu
     * @param list<int> $claimedMonths měsíce, ve kterých je v evidenci uplatněné aspoň jedno dítě
     * @return list<int>
     */
    public static function childMonthsWithoutClaim(array $openingMonths, array $claimedMonths): array
    {
        $claimed = array_fill_keys($claimedMonths, true);
        $missing = [];
        foreach ($openingMonths as $month => $row) {
            $applied = ($row['applied_child_credit_minor_units'] ?? 0)
                + ($row['tax_bonus_minor_units'] ?? 0);
            if ($applied > 0 && !isset($claimed[$month])) {
                $missing[] = (int) $month;
            }
        }
        sort($missing, SORT_NUMERIC);

        return $missing;
    }

    /**
     * Rozpis měsíců z počátečního stavu, jak ho vrací repozitář kumulací.
     *
     * @param array<string,mixed>|null $opening
     * @return array<int,array<string,int>>
     */
    public static function openingMonthRows(?array $opening): array
    {
        $rows = [];
        $months = $opening['evidence']['months'] ?? [];
        foreach (is_array($months) ? $months : [] as $row) {
            if (!is_array($row) || !is_int($row['month'] ?? null)) {
                continue;
            }
            $values = [];
            foreach ($row as $field => $value) {
                if (is_string($field) && is_int($value)) {
                    $values[$field] = $value;
                }
            }
            $rows[$row['month']] = $values;
        }
        ksort($rows);

        return $rows;
    }
}
