<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PayrollEmployeeRepository;
use MyInvoice\Repository\PayrollMonthlyRecordRepository;
use MyInvoice\Service\Accounting\Reports\ReportException;
use PDO;

/**
 * Mzdový list (§38j ZDP) — sestaví roční evidenci zaměstnance z měsíčních snapshotů
 * uložených {@see PayrollPostingService::post()} do `payroll_monthly_records`.
 *
 * ŽÁDNÝ DOPOČET: měsíc bez uloženého záznamu (rekapitulace za něj nikdy neproběhla,
 * nebo proběhla bez vazby na tohoto zaměstnance) se vrátí jako `has_data=false` a
 * skončí v `missing_months` — sestava si nesmí vymýšlet částky, které účetní nikdy
 * nezaúčtovala.
 */
final class PayrollSheetService
{
    private const SUM_KEYS = [
        'gross', 'employee_social', 'employee_health', 'health_min_topup',
        'employee_deductions', 'advance_tax', 'tax_credit_taxpayer', 'tax_credit_children',
        'advance_tax_final', 'net_final', 'employer_total',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmployeeRepository $employees,
        private readonly PayrollMonthlyRecordRepository $records,
    ) {}

    /** @return array<string,mixed> */
    public function build(int $supplierId, int $employeeId, int $year): array
    {
        $employee = $this->employees->find($supplierId, $employeeId);
        if ($employee === null) {
            throw new ReportException('not_found', 'Zaměstnanec nenalezen.', 404);
        }

        $recordsByMonth = $this->records->listForYear($supplierId, $employeeId, $year);

        $rows = [];
        $totals = array_fill_keys(self::SUM_KEYS, 0);
        $missingMonths = [];

        for ($month = 1; $month <= 12; $month++) {
            $rec = $recordsByMonth[$month] ?? null;
            if ($rec === null) {
                $rows[] = ['month' => $month, 'has_data' => false];
                $missingMonths[] = $month;
                continue;
            }
            $b = $rec['breakdown'];
            $row = [
                'month'               => $month,
                'has_data'            => true,
                'gross'               => (int) ($b['gross'] ?? 0),
                'employee_social'     => (int) ($b['employee_social'] ?? 0),
                'employee_health'     => (int) ($b['employee_health'] ?? 0),
                'health_min_topup'    => (int) ($b['health_min_topup'] ?? 0),
                'employee_deductions' => (int) ($b['employee_deductions'] ?? 0),
                'advance_tax'         => (int) ($b['advance_tax'] ?? 0),
                'tax_credit_taxpayer' => $rec['tax_credit_taxpayer'],
                'tax_credit_children' => $rec['tax_credit_children'],
                'advance_tax_final'   => $rec['advance_tax_final'],
                'net_final'           => $rec['net_final'],
                'employer_total'      => (int) ($b['employer_total'] ?? 0),
            ];
            $rows[] = $row;
            foreach (self::SUM_KEYS as $key) {
                $totals[$key] += $row[$key];
            }
        }

        return [
            'year'           => $year,
            'entity'         => $this->entity($supplierId),
            'employee'       => [
                'full_name'           => $employee['full_name'],
                'address'             => $employee['address'] ?? '—',
                'taxpayer_type'       => $employee['taxpayer_type'],
                'tax_credit_taxpayer' => $employee['tax_credit_taxpayer'],
                'child_count'         => $employee['child_count'],
                // Rodné číslo má přednost (§38j odst. 2 písm. a) ZDP); chybí-li, náhradou
                // je datum narození. Předpočteno tady, aby šablona neřešila Twig `default`
                // footgun s explicitním NULL (default filtr bez `true` substituuje jen
                // nedefinované, ne skutečně NULL hodnoty).
                'birth_id_label'      => $employee['birth_number'] !== null ? 'Rodné číslo' : 'Datum narození',
                'birth_id_value'      => $employee['birth_number']
                    ?? ($employee['birth_date'] !== null
                        ? (new \DateTimeImmutable($employee['birth_date']))->format('d.m.Y')
                        : '—'),
            ],
            'rows'           => $rows,
            'totals'         => $totals,
            'missing_months' => $missingMonths,
        ];
    }

    /** @return array<string,mixed> */
    private function entity(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, street, city, zip, ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $addressParts = array_filter([
            trim((string) ($row['street'] ?? '')),
            trim((string) ($row['zip'] ?? '') . ' ' . (string) ($row['city'] ?? '')),
        ], static fn (string $p): bool => $p !== '');

        return [
            'name'        => (string) ($row['company_name'] ?? ''),
            'ico'         => $row['ic'] !== null && $row['ic'] !== '' ? (string) $row['ic'] : null,
            'address'     => implode(', ', $addressParts),
            'prepared_at' => (new \DateTimeImmutable())->format('d.m.Y H:i'),
        ];
    }
}
