<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PayrollNetMigrationTest extends TestCase
{
    public function testNetPayPersistenceIsTenantScopedAndAppendOnlyReady(): void
    {
        $path = dirname(__DIR__, 3)
            . '/db/migrations/1250_payroll_net_pay_and_deductions.sql';
        self::assertFileExists($path);
        $sql = (string) file_get_contents($path);
        $repairPath = dirname(__DIR__, 3)
            . '/db/migrations/1251_payroll_deduction_agreement_owner.sql';
        self::assertFileExists($repairPath);
        $repairSql = (string) file_get_contents($repairPath);

        foreach ([
            'payroll_deduction_agreements',
            'payroll_deduction_ledger',
            'payroll_net_results',
            'payroll_payout_rules',
            'payroll_payout_allocations',
        ] as $table) {
            self::assertStringContainsString(
                "CREATE TABLE IF NOT EXISTS {$table}",
                $sql,
                $table,
            );
        }
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_net_result_revision_employee',
            $sql,
        );
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_deduction_ledger_event',
            $sql,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, revision_id)',
            $sql,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, employee_id)',
            $sql,
        );
        self::assertStringContainsString(
            'CHECK (amount_minor <> 0)',
            $sql,
        );
        foreach ([$sql, $repairSql] as $migrationSql) {
            self::assertStringContainsString(
                'FOREIGN KEY (supplier_id, agreement_id, employee_id)',
                $migrationSql,
            );
            self::assertStringContainsString(
                '(supplier_id, id, employee_id)',
                $migrationSql,
            );
        }
    }
}
