<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use MyInvoice\Service\Payroll\Run\PayrollRunValidation;
use PDO;

/**
 * Roční rozsah dohod o provedení práce u téhož zaměstnavatele (§ 75 ZP).
 *
 * „Rozsah práce, na který se dohoda o provedení práce uzavírá, nesmí být vyšší
 * než 300 hodin v kalendářním roce. Do rozsahu práce se započítává také doba
 * práce konané zaměstnancem pro zaměstnavatele v témže kalendářním roce na
 * základě jiné dohody o provedení práce." (§ 75 zákona č. 262/2006 Sb.)
 *
 * Limit se dosud nehlídal vůbec. U firmy, která přešla na MyÚčto v průběhu
 * roku, navíc část odpracovaných hodin leží jen v převzatých mzdách
 * (`payroll_migration_reference_totals.worked_minutes`) — u dohod je to podle
 * migrace 1851 jediná měřitelná veličina. Počítá se proto:
 *
 *  - vlastní evidence MyÚčta: potvrzené odpracované hodiny pracovního souhrnu
 *    měsíce (táž hodnota, která odchází do měsíčního hlášení),
 *  - převzaté hodiny za měsíce před začátkem vedení mezd (hranici vykládá
 *    {@see PayrollHistoricalPeriodService::precedesStart()}).
 *
 * Součet je za OSOBU přes všechny její dohody o provedení práce u firmy.
 * Překročení je varování, ne závora: odpracovanou práci je potřeba zaplatit,
 * jen smluvní vztah přestal odpovídat zákonu a musí se upravit.
 *
 * Čistý SQL pomocník nad spojením snapshotu — stejně jako dávkový loader se
 * nedává do konstruktoru buildera, aby nepřibyl volitelný parametr, který by
 * DI tiše nevyplnilo.
 */
final class PayrollAgreementAnnualHours
{
    /** 300 hodin v minutách. */
    public const LIMIT_MINUTES = 300 * 60;

    public function __construct(private readonly Connection $db) {}

    /**
     * Odpracované minuty na DPP za rok do konce `$periodStart` včetně, podle osob.
     *
     * @param list<int> $employeeIds
     * @return array<int,array{own_minutes:int,takeover_minutes:int,total_minutes:int}>
     */
    public function yearToDate(int $supplierId, array $employeeIds, string $periodStart): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $year = (int) substr($periodStart, 0, 4);
        $yearStart = sprintf('%04d-01-01', $year);
        $in = implode(', ', array_fill(0, count($employeeIds), '?'));

        $result = [];
        foreach ($employeeIds as $employeeId) {
            $result[$employeeId] = ['own_minutes' => 0, 'takeover_minutes' => 0, 'total_minutes' => 0];
        }

        // Vlastní evidence: aktuální revize pracovního souhrnu každého měsíce.
        $own = $this->db->pdo()->prepare(
            "SELECT employment.employee_id, SUM(summary.worked_millihours) AS millihours
               FROM payroll_time_months month_row
               JOIN payroll_jmhz_work_month_revisions summary
                 ON summary.supplier_id = month_row.supplier_id
                AND summary.time_month_id = month_row.id
                AND summary.time_month_revision_no = month_row.revision_no
               JOIN payroll_employments employment
                 ON employment.supplier_id = month_row.supplier_id
                AND employment.id = month_row.employment_id
              WHERE month_row.supplier_id = ?
                AND employment.relation_type = 'dpp'
                AND employment.employee_id IN ({$in})
                AND month_row.period_start >= ? AND month_row.period_start <= ?
              GROUP BY employment.employee_id",
        );
        $own->execute([$supplierId, ...$employeeIds, $yearStart, $periodStart]);
        foreach ($own->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employee_id']]['own_minutes'] = intdiv((int) $row['millihours'] * 60, 1000);
        }

        // Převzaté hodiny za měsíce před začátkem vedení mezd v MyÚčtu.
        $startPeriod = $this->startPeriod($supplierId);
        $takeover = $this->db->pdo()->prepare(
            "SELECT totals.employee_id, totals.period_start, SUM(totals.worked_minutes) AS minutes
               FROM payroll_migration_reference_totals totals
               JOIN payroll_employments employment
                 ON employment.supplier_id = totals.supplier_id
                AND employment.id = totals.employment_id
              WHERE totals.supplier_id = ?
                AND employment.relation_type = 'dpp'
                AND totals.employee_id IN ({$in})
                AND totals.period_start >= ? AND totals.period_start <= ?
              GROUP BY totals.employee_id, totals.period_start",
        );
        $takeover->execute([$supplierId, ...$employeeIds, $yearStart, $periodStart]);
        foreach ($takeover->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!PayrollHistoricalPeriodService::precedesStart($startPeriod, (string) $row['period_start'])) {
                continue;
            }
            $result[(int) $row['employee_id']]['takeover_minutes'] += (int) $row['minutes'];
        }

        foreach ($result as $employeeId => $row) {
            $result[$employeeId]['total_minutes'] = $row['own_minutes'] + $row['takeover_minutes'];
        }

        return $result;
    }

    /**
     * Varování pro DPP vztahy běhu, jejichž osoba limit v roce překročila.
     *
     * @param list<array<string,mixed>> $employments řádky běhu s `employment_id`,
     *        `employee_id`, `relation_type` a `full_name`
     * @return list<PayrollRunValidation>
     */
    public function validations(int $supplierId, array $employments, string $periodStart): array
    {
        $agreements = array_values(array_filter(
            $employments,
            static fn (array $row): bool => ($row['relation_type'] ?? null) === 'dpp',
        ));
        if ($agreements === []) {
            return [];
        }
        $employeeIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['employee_id'],
            $agreements,
        )));
        $hours = $this->yearToDate($supplierId, $employeeIds, $periodStart);
        $validations = [];
        foreach ($agreements as $row) {
            $employeeId = (int) $row['employee_id'];
            $employmentId = (int) $row['employment_id'];
            $total = $hours[$employeeId]['total_minutes'] ?? 0;
            if ($total <= self::LIMIT_MINUTES) {
                continue;
            }
            $validations[] = new PayrollRunValidation(
                'warning',
                'dpp_annual_hours_exceeded',
                'employment',
                $employmentId,
                self::message(
                    (string) ($row['full_name'] ?? ''),
                    (int) substr($periodStart, 0, 4),
                    $total,
                    $hours[$employeeId]['takeover_minutes'] ?? 0,
                ),
                "/payroll/people?person={$employeeId}&employment={$employmentId}&panel=employment_terms",
            );
        }

        return $validations;
    }

    public static function message(string $name, int $year, int $totalMinutes, int $takeoverMinutes): string
    {
        return sprintf(
            '%s: na dohodách o provedení práce odpracoval(a) v roce %d celkem %s h%s — víc než 300 h, '
                . 'které § 75 zákoníku práce u téhož zaměstnavatele dovoluje. Práce nad limit už nejde '
                . 'vykonávat na DPP; upravte smluvní vztah (dohoda o pracovní činnosti nebo pracovní poměr) '
                . 'v kartě zaměstnance.',
            $name === '' ? 'Zaměstnanec' : $name,
            $year,
            self::hours($totalMinutes),
            $takeoverMinutes > 0
                ? sprintf(' (z toho %s h převzatých z předchozího programu)', self::hours($takeoverMinutes))
                : '',
        );
    }

    private static function hours(int $minutes): string
    {
        $whole = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0
            ? number_format($whole, 0, ',', ' ')
            : number_format($minutes / 60, 2, ',', ' ');
    }

    private function startPeriod(int $supplierId): ?string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT start_period FROM payroll_module_state WHERE supplier_id = ?',
        );
        $statement->execute([$supplierId]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }
}
