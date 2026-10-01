<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Je mzda pracovního vztahu za měsíc už uzavřená?
 *
 * Uzavřená = vztah je v AKTUÁLNÍ revizi běhu za ten měsíc a běh je schválený
 * nebo dál (zaúčtovaný, k úhradě, vyplacený, uzavřený). Takový měsíc se už
 * nepřepočítá, takže nový nebo změněný podklad by v přehledu vypadal, že platí,
 * a do výplaty by se nedostal — nebo by ho tiše převzala až příští opravná revize.
 *
 * Běh OTEVŘENÝ K OPRAVĚ (`correction_pending`, `reopened`) uzavřený není: právě
 * v něm se opravné podklady zadávají. Stejný výklad drží
 * {@see PayrollApprovedPeriodFreeze}; liší se jen tím, že zmrazení evidence je
 * jedno datum za firmu, kdežto tady se rozhoduje po vztazích a po měsíci, protože
 * běhy jsou po mzdových účtárnách a jeden měsíc jich může mít víc.
 *
 * Import docházky má vlastní, přísnější seznam stavů
 * (`AttendanceImportService::WAGE_BLOCKING_RUN_STATUSES`, blokuje i
 * `correction_pending`) a dívá se na kteroukoli revizi, ne jen aktuální. Je to
 * záměr: hromadný import do měsíce rozpracované opravy nezapisuje nikdy, ruční
 * zadání ano. Proto se tu nesjednocuje.
 */
final class PayrollClosedRunGuard
{
    public const FINISHED_STATUSES = ['approved', 'posted', 'payment_ready', 'paid', 'closed'];

    public function __construct(private readonly Connection $db) {}

    /**
     * Uzavírající běh po vztazích, jedním dotazem pro celou stránku.
     *
     * @param list<int>|null $employmentIds null = všechny vztahy měsíce
     * @return array<int,array{run_id:int,status:string,period:string}> klíč = employment_id
     */
    public function closingRuns(int $supplierId, string $period, ?array $employmentIds = null): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $periodStart = substr($period, 0, 7) . '-01';
        $statuses = implode(',', array_fill(0, count(self::FINISHED_STATUSES), '?'));
        $employmentFilter = $employmentIds === null
            ? ''
            : ' AND run_employment.employment_id IN ('
                . implode(',', array_fill(0, count($employmentIds), '?')) . ')';
        $stmt = $this->db->pdo()->prepare(
            'SELECT run_employment.employment_id, run.id AS run_id, run.status, run.period_start
               FROM payroll_runs run
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
               JOIN payroll_run_employments run_employment
                 ON run_employment.supplier_id = revision.supplier_id
                AND run_employment.revision_id = revision.id
              WHERE run.supplier_id = ?
                AND run.period_start = ?
                AND run.status IN (' . $statuses . ')'
            . $employmentFilter
            . ' ORDER BY run.id'
        );
        $stmt->execute([
            $supplierId,
            $periodStart,
            ...self::FINISHED_STATUSES,
            ...($employmentIds ?? []),
        ]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $employmentId = (int) $row['employment_id'];
            $result[$employmentId] ??= [
                'run_id' => (int) $row['run_id'],
                'status' => (string) $row['status'],
                'period' => substr((string) $row['period_start'], 0, 7),
            ];
        }

        return $result;
    }

    /** @return array{run_id:int,status:string,period:string}|null */
    public function closingRun(int $supplierId, string $period, int $employmentId): ?array
    {
        return $this->closingRuns($supplierId, $period, [$employmentId])[$employmentId] ?? null;
    }

    /** @throws PayrollRunClosedException */
    public function assertOpen(int $supplierId, string $period, int $employmentId): void
    {
        $run = $this->closingRun($supplierId, $period, $employmentId);
        if ($run !== null) {
            throw new PayrollRunClosedException($run);
        }
    }

    /** `2026-09` → `9/2026` */
    public static function periodLabel(string $period): string
    {
        return (int) substr($period, 5, 2) . '/' . substr($period, 0, 4);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'schválený',
            'posted' => 'zaúčtovaný',
            'payment_ready' => 'připravený k úhradě',
            'paid' => 'vyplacený',
            'closed' => 'uzavřený',
            default => $status,
        };
    }
}
