<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

/**
 * Hrubá a čistá mzda z mzdového exportu v podkladech (reference) proti výpočtu
 * mzdového běhu téhož období. Slouží ke kontrole při přechodu z jiného mzdového
 * systému: stejné podklady mají dát stejnou mzdu.
 *
 * Čistá mzda se ve výpočtu vede za zaměstnance, ne za vztah — u osoby s více
 * vztahy se proto neporovnává.
 */
final class AttendanceReferenceComparison
{
    /** Rozdíl do koruny je zaokrouhlení, ne nesoulad. */
    public const TOLERANCE_MINOR = 100;

    private const STATUS_ORDER = ['diff' => 0, 'missing' => 1, 'match' => 2];

    /**
     * @param list<array<string,mixed>> $rows řádky dávky ({@see \MyInvoice\Repository\Payroll\PayrollAttendanceImportRepository::batchRows()})
     * @param array{run:?array{status:string,revision_status:?string},gross:array<int,int>,net:array<int,int>,employee_of:array<int,int>,employment_count:array<int,int>} $results
     * @return array{run:?array{status:string,revision_status:?string},rows:list<array<string,mixed>>,summary:array{match:int,diff:int,missing:int}}
     */
    public static function compare(array $rows, array $results): array
    {
        $references = [];
        foreach ($rows as $row) {
            $meaning = (string) ($row['meaning'] ?? '');
            if (!in_array($meaning, ['reference_gross', 'reference_net'], true) || !is_int($row['amount_minor'] ?? null)) {
                continue;
            }
            $employmentId = (int) $row['employment_id'];
            $references[$employmentId] ??= [
                'employment_id' => $employmentId,
                'employee_name' => (string) ($row['employee_name'] ?? ''),
                'employment_code' => (string) ($row['employment_code'] ?? ''),
                'reference_gross_minor' => null,
                'reference_net_minor' => null,
            ];
            $references[$employmentId][$meaning === 'reference_gross' ? 'reference_gross_minor' : 'reference_net_minor']
                = $row['amount_minor'];
        }

        $result = [];
        $summary = ['match' => 0, 'diff' => 0, 'missing' => 0];
        foreach ($references as $employmentId => $reference) {
            $gross = $results['gross'][$employmentId] ?? null;
            $employeeId = $results['employee_of'][$employmentId] ?? null;
            $shared = $employeeId !== null && ($results['employment_count'][$employeeId] ?? 0) > 1;
            $net = $employeeId !== null && !$shared ? ($results['net'][$employeeId] ?? null) : null;
            $grossDiff = $gross !== null && $reference['reference_gross_minor'] !== null
                ? $gross - $reference['reference_gross_minor']
                : null;
            $netDiff = $net !== null && $reference['reference_net_minor'] !== null
                ? $net - $reference['reference_net_minor']
                : null;
            $status = match (true) {
                $gross === null => 'missing',
                abs($grossDiff ?? 0) > self::TOLERANCE_MINOR || abs($netDiff ?? 0) > self::TOLERANCE_MINOR => 'diff',
                default => 'match',
            };
            ++$summary[$status];
            $result[] = $reference + [
                'computed_gross_minor' => $gross,
                'gross_diff_minor' => $grossDiff,
                'computed_net_minor' => $net,
                'net_diff_minor' => $netDiff,
                'net_shared' => $shared,
                'status' => $status,
            ];
        }
        usort($result, static fn (array $a, array $b): int => [self::STATUS_ORDER[$a['status']], $a['employee_name']]
            <=> [self::STATUS_ORDER[$b['status']], $b['employee_name']]);

        return ['run' => $results['run'], 'rows' => $result, 'summary' => $summary];
    }
}
