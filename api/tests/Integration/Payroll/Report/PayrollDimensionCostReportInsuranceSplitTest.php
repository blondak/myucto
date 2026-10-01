<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Report;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Payroll\Posting\PayrollApprovedRevisionPostingService;
use MyInvoice\Service\Payroll\Report\PayrollDimensionCostReportService;
use MyInvoice\Tests\Support\EnforcementRunFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Report nákladů na zaměstnance po dimenzi u firmy, jejíž vztahy nenesou
 * žádnou mzdovou dimenzi. Pojistné zaměstnavatele se pak zaúčtuje jednou
 * firemní částkou, report ho ale musí rozdělit na zaměstnance poměrem
 * vyměřovacích základů, ne nechat v řádku „nerozděleno".
 */
#[Group('integration')]
final class PayrollDimensionCostReportInsuranceSplitTest extends TestCase
{
    use EnforcementRunFixtureTrait;

    protected function setUp(): void
    {
        $this->bootEnforcementRun(3_500_000);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier_accounting_modes WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $this->service(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->service(AccountingPeriodRepository::class)
            ->create($this->supplierId, 2026, '2026-01-01', '2026-12-31');
    }

    protected function tearDown(): void
    {
        $this->tearDownEnforcementRun();
    }

    public function testEmployerInsuranceIsSplitByAssessmentBases(): void
    {
        $this->setDimensionsEnabled(true);
        $secondEmployment = $this->addEmployee(2_000_000);
        $this->postRun();

        $report = $this->service(PayrollDimensionCostReportService::class)
            ->report($this->supplierId, 2026);

        self::assertTrue($report['enabled']);
        $byEmployment = [];
        foreach ($report['rows'] as $row) {
            self::assertNotNull(
                $row['employment_id'],
                'Pojistné zaměstnavatele nesmí zůstat v řádku „nerozděleno".',
            );
            $byEmployment[$row['employment_id']] = $row;
        }
        ksort($byEmployment);
        self::assertSame([$this->employmentId, $secondEmployment], array_keys($byEmployment));

        $insurance = $this->journalDebit('524');
        self::assertGreaterThan(0, $insurance);
        $first = $byEmployment[$this->employmentId]['insurance_minor'];
        $second = $byEmployment[$secondEmployment]['insurance_minor'];
        self::assertSame($insurance, $first + $second, 'Součet podílů sedí na firemní částku na haléř.');
        // Sociální i zdravotní se dělí zvlášť poměrem základů 35 000 : 20 000.
        self::assertEqualsWithDelta($insurance * 35 / 55, $first, 2);
        self::assertEqualsWithDelta($insurance * 20 / 55, $second, 2);
        self::assertSame(3_500_000, $byEmployment[$this->employmentId]['wages_minor']);
        self::assertSame(2_000_000, $byEmployment[$secondEmployment]['wages_minor']);
        self::assertSame(
            $this->journalDebit('5'),
            $report['totals']['total_minor'],
            'Report nákladů sedí na nákladové řádky deníku.',
        );
    }

    public function testFirmWithoutDimensionsGetsADisabledReport(): void
    {
        $this->setDimensionsEnabled(false);
        $this->db->pdo()->prepare('DELETE FROM payroll_dimensions WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $this->postRun();

        $report = $this->service(PayrollDimensionCostReportService::class)
            ->report($this->supplierId, 2026);

        self::assertFalse($report['enabled']);
        self::assertSame([], $report['rows']);
    }

    private function setDimensionsEnabled(bool $enabled): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET dimensions_enabled = ? WHERE id = ?')
            ->execute([$enabled ? 1 : 0, $this->supplierId]);
    }

    private function postRun(): void
    {
        $run = $this->calculateEnforcementRun();
        $this->approveEnforcementRun($run);
        $stmt = $this->db->pdo()->prepare(
            'SELECT input_snapshot_json, result_snapshot_json
               FROM payroll_run_revisions
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$this->supplierId, $run['revision_id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertNotNull($this->service(PayrollApprovedRevisionPostingService::class)->postManually(
            $this->supplierId,
            $run['revision_id'],
            json_decode((string) $row['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR),
            json_decode((string) $row['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR),
            $this->actorId,
        ));
    }

    /** Druhý zaměstnanec ve stejné účtárně; vrací jeho pracovní vztah. */
    private function addEmployee(int $grossMinor): int
    {
        $pdo = $this->db->pdo();
        [$firstEmployee, $firstEmployment] = [$this->employeeId, $this->employmentId];
        $office = $pdo->prepare('SELECT office_id FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $office->execute([$this->supplierId, $firstEmployment]);
        $officeId = (int) $office->fetchColumn();

        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetický Druhý", "employee", 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "ready")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date,
                 actual_start_date, monthly_gross_minor, is_primary, office_id)
             VALUES (?, ?, "SYN-HPP-2", "employment", "active", "2026-01-01",
                     "2026-01-01", ?, 1, ?)'
        )->execute([$this->supplierId, $this->employeeId, $grossMinor, $officeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, effective_from, planned_start_on,
                 actual_start_on, weekly_hours, workload_basis_points,
                 social_insurance_participation, health_insurance_participation,
                 tax_regime, other_withholding_eligibility, tax_declaration_signed, is_primary)
             VALUES (?, ?, "2026-01-01", "2026-01-01", "2026-01-01", 40, 10000,
                     "automatic", "automatic", "advance", "unverified", 0, 1)'
        )->execute([$this->supplierId, $this->employmentId]);
        $this->seedRunStatutoryEvidence();
        $this->seedRunZeroOpenings();
        $this->seedRunInput($this->componentIds['MZDA_MESICNI'], $grossMinor);

        $second = $this->employmentId;
        [$this->employeeId, $this->employmentId] = [$firstEmployee, $firstEmployment];

        return $second;
    }

    private function journalDebit(string $accountPrefix): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CAST(ROUND(line.amount * 100) AS SIGNED)), 0)
               FROM journal_entry_lines line
               JOIN journal_entries entry
                 ON entry.supplier_id = line.supplier_id AND entry.id = line.entry_id
               JOIN chart_of_accounts account
                 ON account.supplier_id = line.supplier_id AND account.id = line.account_id
              WHERE line.supplier_id = ?
                AND entry.source_type = 'payroll'
                AND line.side = 'debit'
                AND account.account_code LIKE ?"
        );
        $stmt->execute([$this->supplierId, $accountPrefix . '%']);

        return (int) $stmt->fetchColumn();
    }
}
