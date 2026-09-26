<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use PDO;

/**
 * Převzatá část roku přechodu: které měsíce MyÚčto nepočítalo, komu v nich
 * trval vztah a jestli za ně existuje počáteční stav.
 *
 * ── Proč existuje ───────────────────────────────────────────────────────────
 * Firma, která začne vést mzdy v MyÚčtu od října, má leden až září doložené
 * jen počátečními stavy kumulací (`payroll_statutory_accumulator_openings`).
 * Roční zúčtování i potvrzení o zdanitelných příjmech je čtou, jenže roční
 * vyúčtování daně je dřív nečetlo vůbec — převzaté měsíce v něm tiše chyběly.
 * A u vztahu, který v převzatém měsíci trval, se chybějící měsíc nedal odlišit
 * od „nebylo co převzít".
 *
 * Tahle třída je JEDINÉ místo, které na obě otázky odpovídá. Hranici vykládá
 * {@see PayrollHistoricalPeriodService}, trvání vztahu
 * {@see PayrollEmploymentMonths} a počáteční stavy čte týž repozitář, ze
 * kterého počítá roční zúčtování — vyúčtování, uzavření roku a kontrola shody
 * převzatých vrstev se tak nemůžou rozejít s tím, co si myslí roční doklady.
 */
final class PayrollTakeoverCoverage
{
    /**
     * Druhy kumulace, ze kterých se bere měsíční rozpis. Průvodce počátečních
     * stavů ho píše do všech shodně; přednost má daňová kumulace, stejně jako
     * u ročních dokladů ({@see \MyInvoice\Service\Payroll\Document\PayrollCarriedOverPeriod}).
     */
    private const KINDS = ['income_tax', 'social_insurance', 'health_insurance'];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollStatutoryAccumulatorRepository $accumulators,
        private readonly PayrollHistoricalPeriodService $historical,
    ) {}

    /**
     * Měsíce roku před prvním mzdovým obdobím v MyÚčtu, nebo `[]`.
     *
     * @return list<int>
     */
    public function takeoverMonths(int $supplierId, int $year): array
    {
        return self::monthsBeforeStart($this->historical->startPeriod($supplierId), $year);
    }

    /**
     * Čistá podoba pravidla. Převzaté měsíce má jen rok, ve kterém vedení mezd
     * začalo: dřívější rok MyÚčto nevedlo vůbec a pozdější celý počítá samo —
     * stejně to čte {@see \MyInvoice\Service\Payroll\Document\PayrollCarriedOverPeriodReader::expectedCarryStart()}.
     *
     * @return list<int>
     */
    public static function monthsBeforeStart(?string $startPeriod, int $year): array
    {
        if ($startPeriod === null || preg_match('/^(\d{4})-(\d{2})/', $startPeriod, $match) !== 1) {
            return [];
        }
        if ((int) $match[1] !== $year) {
            return [];
        }
        $first = (int) $match[2];

        return $first <= 1 ? [] : range(1, $first - 1);
    }

    /**
     * Rozpis měsíců počátečních stavů podle osob.
     *
     * Osoba s počátečním stavem bez rozpisu (doložená nula) má prázdné pole;
     * osoba bez počátečního stavu ve výsledku není vůbec. To je rozdíl, na
     * kterém záleží: první je „převzít nebylo co", druhé „nikdo nic nezadal".
     *
     * @return array<int,array<int,array<string,int>>> employee_id => měsíc => řádek
     */
    public function openingMonths(int $supplierId, int $year): array
    {
        $result = [];
        foreach (self::KINDS as $kind) {
            foreach ($this->accumulators->currentOpeningsForSupplier($supplierId, $year, $kind) as $employeeId => $opening) {
                if (isset($result[$employeeId])) {
                    continue;
                }
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
                $result[$employeeId] = $rows;
            }
        }
        ksort($result);

        return $result;
    }

    /**
     * Převzaté měsíce, ve kterých osobě trval pracovní vztah.
     *
     * @param list<int> $takeoverMonths
     * @return array<int,list<int>> employee_id => měsíce
     */
    public function expectedMonths(int $supplierId, int $year, array $takeoverMonths): array
    {
        if ($takeoverMonths === []) {
            return [];
        }
        $lastMonth = max($takeoverMonths);
        $statement = $this->db->pdo()->prepare(sprintf(
            'SELECT employee_id, start_date, end_date
               FROM payroll_employments
              WHERE supplier_id = ?
                AND status IN (%s)
                AND (start_date IS NULL OR start_date <= ?)
                AND (end_date IS NULL OR end_date >= ?)
              ORDER BY employee_id, id',
            implode(', ', array_fill(0, count(PayrollEmploymentMonths::STATUSES), '?')),
        ));
        $statement->execute([
            $supplierId,
            ...PayrollEmploymentMonths::STATUSES,
            date('Y-m-t', (int) strtotime(sprintf('%04d-%02d-01', $year, $lastMonth))),
            sprintf('%04d-01-01', $year),
        ]);
        $spans = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $spans[(int) $row['employee_id']][] = $row;
        }
        $expected = [];
        foreach ($spans as $employeeId => $rows) {
            $months = array_values(array_intersect(
                PayrollEmploymentMonths::covered($rows, $year),
                $takeoverMonths,
            ));
            if ($months !== []) {
                $expected[$employeeId] = $months;
            }
        }

        return $expected;
    }

    /**
     * Osoby, kterým v převzatém měsíci trval vztah, ale počáteční stav ten
     * měsíc nemá.
     *
     * Prázdný měsíc není nula: když za něj rozpis nic neříká, nikdo netvrdí,
     * že příjem nebyl. Doložená nula je řádek rozpisu s nulami (potvrzený při
     * zadání), ne chybějící řádek.
     *
     * @return list<array{employee_id:int,employee_name:string,missing_months:list<int>}>
     */
    public function gaps(int $supplierId, int $year): array
    {
        $takeover = $this->takeoverMonths($supplierId, $year);
        if ($takeover === []) {
            return [];
        }
        $openings = $this->openingMonths($supplierId, $year);
        $gaps = [];
        foreach ($this->expectedMonths($supplierId, $year, $takeover) as $employeeId => $months) {
            $missing = array_values(array_diff($months, array_keys($openings[$employeeId] ?? [])));
            if ($missing !== []) {
                $gaps[$employeeId] = $missing;
            }
        }
        if ($gaps === []) {
            return [];
        }
        $names = $this->employeeNames($supplierId, array_keys($gaps));
        $result = [];
        foreach ($gaps as $employeeId => $missing) {
            $result[] = [
                'employee_id' => $employeeId,
                'employee_name' => $names[$employeeId] ?? ('#' . $employeeId),
                'missing_months' => $missing,
            ];
        }

        return $result;
    }

    /**
     * Srozumitelný popis mezer pro hlášku: „Jana Nováková (1–3), Petr Svoboda (9)".
     *
     * @param list<array{employee_id:int,employee_name:string,missing_months:list<int>}> $gaps
     */
    public static function describeGaps(array $gaps, int $limit = 10): string
    {
        $parts = [];
        foreach (array_slice($gaps, 0, $limit) as $gap) {
            $parts[] = sprintf('%s (%s)', $gap['employee_name'], self::monthRanges($gap['missing_months']));
        }
        if (count($gaps) > $limit) {
            $parts[] = sprintf('a %d dalších', count($gaps) - $limit);
        }

        return implode(', ', $parts);
    }

    /** @param list<int> $months */
    public static function monthRanges(array $months): string
    {
        if ($months === []) {
            return '';
        }
        sort($months, SORT_NUMERIC);
        $ranges = [];
        $start = $months[0];
        $previous = $start;
        foreach (array_slice($months, 1) as $month) {
            if ($month === $previous + 1) {
                $previous = $month;
                continue;
            }
            $ranges[] = $start === $previous ? (string) $start : "{$start}–{$previous}";
            $start = $month;
            $previous = $month;
        }
        $ranges[] = $start === $previous ? (string) $start : "{$start}–{$previous}";

        return implode(', ', $ranges);
    }

    /**
     * @param list<int> $employeeIds
     * @return array<int,string>
     */
    public function employeeNames(int $supplierId, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(sprintf(
            'SELECT id, full_name FROM payroll_employees WHERE supplier_id = ? AND id IN (%s)',
            implode(', ', array_fill(0, count($employeeIds), '?')),
        ));
        $statement->execute([$supplierId, ...$employeeIds]);
        $names = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim((string) ($row['full_name'] ?? ''));
            $names[(int) $row['id']] = $name !== '' ? $name : ('#' . (int) $row['id']);
        }

        return $names;
    }
}
