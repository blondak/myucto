<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Množinové načtení podkladů mzdového snapshotu.
 *
 * Snapshot dřív sahal do databáze zvlášť za každou osobu a každý pracovní vztah,
 * takže běh nad ~300 osobami vygeneroval tisíce round-tripů uvnitř jedné transakce
 * a nedoběhl. Tenhle loader načte tytéž řádky jedním dotazem na tabulku (po
 * dávkách nad zmrazenou množinou ID) a seskupí je v PHP.
 *
 * Dvě pravidla, na kterých stojí bajtová shoda snapshotu:
 *
 * 1. `ORDER BY` zůstává PŘESNĚ takový, jaký byl v dotazu za jednu osobu. Podposloupnost
 *    globálně seřazeného výsledku pro jedno ID má totiž stejné pořadí jako samostatný
 *    dotaz — přidávat do řazení skupinový klíč by bylo zbytečné a maskovalo by chybu.
 * 2. Seskupovací sloupec se vybírá pod vlastním aliasem a po seskupení se z řádku
 *    zase odstraní, takže volající dostane bajtově týž řádek jako dřív.
 */
final class PayrollRunSnapshotBatchLoader
{
    /**
     * Velikost dávky. Drží počet parametrů dotazu hluboko pod limitem serveru
     * i pod hranicí, kde optimalizátor přestává používat range scan nad IN.
     */
    public const CHUNK_SIZE = 500;

    /** Alias skupinového klíče — nesmí kolidovat se sloupcem žádné z tabulek. */
    private const GROUP_KEY = 'snapshot_group_key';

    public function __construct(private readonly Connection $db) {}

    /**
     * Docházkové měsíce podle pracovního vztahu.
     *
     * `payroll_time_months` má UNIQUE (supplier_id, employment_id, period_start),
     * takže na vztah připadá nejvýš jeden řádek — stejně jako u původního fetch().
     *
     * @param list<int> $employmentIds
     * @return array<int,array<string,mixed>>
     */
    public function timeMonths(
        int $supplierId,
        array $employmentIds,
        string $periodStart,
    ): array {
        return $this->single($this->fetch(
            'SELECT month_row.id, month_row.status, month_row.revision_no,
                    month_row.row_version, month_row.approved_at,
                    summary.id AS summary_id,
                    spec.package_key AS spec_package_key,
                    spec.manifest_sha256 AS stored_spec_manifest_sha256,
                    summary.spec_manifest_sha256,
                    summary.scenario_catalog_key,
                    summary.scenario_manifest_sha256,
                    summary.control_catalog_key,
                    summary.control_manifest_sha256,
                    summary.derivation_version,
                    summary.source_snapshot_json,
                    summary.source_snapshot_sha256,
                    summary.standard_fund_millihours,
                    summary.agreed_fund_millihours,
                    summary.weekly_work_centihours,
                    summary.evidence_days,
                    summary.worked_millihours,
                    summary.conditional_blocks_confirmed,
                    summary.unworked_hours_occurred,
                    summary.work_obstacles_occurred,
                    summary.unworked_total_millihours,
                    summary.unworked_paid_millihours,
                    summary.dpn_without_employer_compensation_millihours,
                    summary.dpn_with_employer_compensation_millihours,
                    summary.vacation_millihours,
                    summary.care_millihours,
                    summary.employee_obstacle_paid_millihours,
                    summary.employer_obstacle_millihours,
                    summary.confirmation_note,
                    summary.provenance_json,
                    summary.summary_sha256,
                    month_row.employment_id AS ' . self::GROUP_KEY . '
               FROM payroll_time_months month_row
               LEFT JOIN payroll_jmhz_work_month_revisions summary
                 ON summary.supplier_id = month_row.supplier_id
                AND summary.time_month_id = month_row.id
                AND summary.time_month_revision_no = month_row.revision_no
               LEFT JOIN payroll_jmhz_spec_packages spec
                 ON spec.id = summary.spec_package_id
              WHERE month_row.supplier_id = ?
                AND month_row.employment_id IN (%s)
                AND month_row.period_start = ?',
            [$supplierId],
            $employmentIds,
            [$periodStart],
        ));
    }

    /**
     * Počty neschválených vstupů podle pracovního vztahu.
     *
     * @param list<int> $employmentIds
     * @return array<int,int>
     */
    public function draftInputCounts(
        int $supplierId,
        array $employmentIds,
        string $periodStart,
    ): array {
        $counts = [];
        foreach ($this->fetch(
            'SELECT employment_id AS ' . self::GROUP_KEY . ', COUNT(*) AS draft_count
               FROM payroll_inputs
              WHERE supplier_id = ?
                AND employment_id IN (%s)
                AND period_start = ?
                AND status = "draft"
              GROUP BY employment_id',
            [$supplierId],
            $employmentIds,
            [$periodStart],
        ) as $row) {
            $counts[(int) $row[self::GROUP_KEY]] = (int) $row['draft_count'];
        }

        return $counts;
    }

    /**
     * Schválené mzdové vstupy podle pracovního vztahu.
     *
     * @param list<int> $employmentIds
     * @return array<int,list<array<string,mixed>>>
     */
    public function inputs(
        int $supplierId,
        array $employmentIds,
        string $periodStart,
    ): array {
        return $this->grouped($this->fetch(
            'SELECT id, amount_minor, quantity_milliunits, source_kind,
                    source_period_start, component_snapshot_json,
                    HEX(component_snapshot_hash) AS component_snapshot_hash,
                    employment_id AS ' . self::GROUP_KEY . '
               FROM payroll_inputs
              WHERE supplier_id = ?
                AND employment_id IN (%s)
                AND period_start = ?
                AND status IN ("approved", "locked")
              ORDER BY id',
            [$supplierId],
            $employmentIds,
            [$periodStart],
        ));
    }

    /**
     * Schválené absence zasahující do období, podle pracovního vztahu.
     *
     * @param list<int> $employmentIds
     * @return array<int,list<array<string,mixed>>>
     */
    public function absences(
        int $supplierId,
        array $employmentIds,
        string $periodStart,
        string $periodEnd,
    ): array {
        return $this->grouped($this->fetch(
            'SELECT id, absence_type, date_from, date_to,
                    partial_first_minutes, partial_last_minutes, timezone_name,
                    compensation_policy, average_snapshot_id, decided_at,
                    employment_id AS ' . self::GROUP_KEY . '
               FROM payroll_absences
              WHERE supplier_id = ?
                AND employment_id IN (%s)
                AND status = "approved"
                AND date_from <= ?
                AND date_to >= ?
              ORDER BY date_from, id',
            [$supplierId],
            $employmentIds,
            [$periodEnd, $periodStart],
        ));
    }

    /**
     * Účinné dohody o srážkách podle zaměstnance.
     *
     * @param list<int> $employeeIds
     * @return array<int,list<array<string,mixed>>>
     */
    public function deductionAgreements(
        int $supplierId,
        array $employeeIds,
        string $effectiveOn,
    ): array {
        return $this->grouped($this->fetch(
            'SELECT id, agreement_reference, title, deduction_kind, priority_no,
                    requested_minor, total_limit_minor, withheld_total_minor,
                    valid_from, valid_to, row_version,
                    employee_id AS ' . self::GROUP_KEY . '
               FROM payroll_deduction_agreements
              WHERE supplier_id = ?
                AND employee_id IN (%s)
                AND status = "active"
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY priority_no, id',
            [$supplierId],
            $employeeIds,
            [$effectiveOn, $effectiveOn],
        ));
    }

    /**
     * Aktivní výplatní pravidla podle zaměstnance — se zámkem, jako dřív.
     *
     * Pořadí zamykání zůstává „nejdřív pravidla, pak účty"; uvnitř tabulky teď
     * zámky padnou v jednom průchodu indexu místo N samostatných dotazů.
     *
     * @param list<int> $employeeIds
     * @return array<int,list<array<string,mixed>>>
     */
    public function payoutRules(int $supplierId, array $employeeIds): array
    {
        return $this->grouped($this->fetch(
            'SELECT id, allocation_reference, destination_kind,
                    destination_reference, allocation_kind, amount_minor,
                    basis_points, priority_no, row_version,
                    employee_id AS ' . self::GROUP_KEY . '
               FROM payroll_payout_rules
              WHERE supplier_id = ?
                AND employee_id IN (%s)
                AND is_active = 1
              ORDER BY priority_no, id
              FOR UPDATE',
            [$supplierId],
            $employeeIds,
            [],
        ));
    }

    /**
     * Účinné výplatní účty podle zaměstnance — se zámkem, jako dřív.
     *
     * @param list<int> $employeeIds
     * @return array<int,list<array<string,mixed>>>
     */
    public function payoutAccounts(
        int $supplierId,
        array $employeeIds,
        string $paymentDate,
    ): array {
        return $this->grouped($this->fetch(
            'SELECT id, label, LOWER(HEX(bank_account_hash)) AS bank_account_hash,
                    bank_account_masked, allocation_basis_points,
                    effective_from, effective_to, row_version,
                    verification_source, verified_on, verified_by,
                    employee_id AS ' . self::GROUP_KEY . '
               FROM payroll_person_accounts
              WHERE supplier_id = ?
                AND employee_id IN (%s)
                AND is_active = 1
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY id
              FOR UPDATE',
            [$supplierId],
            $employeeIds,
            [$paymentDate, $paymentDate],
        ));
    }

    /**
     * Spustí dotaz po dávkách a vrátí spojený výsledek.
     *
     * @param list<mixed> $leading parametry PŘED seznamem ID
     * @param list<int> $ids
     * @param list<mixed> $trailing parametry ZA seznamem ID
     * @return list<array<string,mixed>>
     */
    private function fetch(
        string $sql,
        array $leading,
        array $ids,
        array $trailing,
    ): array {
        $unique = array_values(array_unique($ids));
        if ($unique === []) {
            return [];
        }
        $rows = [];
        foreach (array_chunk($unique, self::CHUNK_SIZE) as $chunk) {
            $statement = $this->db->pdo()->prepare(
                sprintf($sql, implode(', ', array_fill(0, count($chunk), '?'))),
            );
            $statement->execute([...$leading, ...$chunk, ...$trailing]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,list<array<string,mixed>>>
     */
    private function grouped(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $key = (int) $row[self::GROUP_KEY];
            unset($row[self::GROUP_KEY]);
            $grouped[$key][] = $row;
        }

        return $grouped;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function single(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $key = (int) $row[self::GROUP_KEY];
            unset($row[self::GROUP_KEY]);
            $indexed[$key] ??= $row;
        }

        return $indexed;
    }
}
