<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollPersonStatutoryEvidenceMigrationTest extends TestCase
{
    public function testMigrationDefinesTenantScopedVersionedEvidenceTables(): void
    {
        $sql = $this->migration();

        foreach ([
            'payroll_person_health_coverage_history',
            'payroll_person_health_minimum_reductions',
            'payroll_person_health_month_evidence',
            'payroll_person_health_other_employer_bases',
            'payroll_person_tax_declarations',
            'payroll_person_tax_residences',
            'payroll_person_tax_credit_claims',
            'payroll_person_tax_child_claims',
            'payroll_person_social_jurisdictions',
            'payroll_person_social_discount_claims',
        ] as $table) {
            self::assertStringContainsString("CREATE TABLE IF NOT EXISTS {$table}", $sql);
        }

        self::assertSame(10, substr_count(
            $sql,
            'FOREIGN KEY (supplier_id, employee_id)',
        ));
        self::assertSame(10, substr_count(
            $sql,
            'row_version',
        ));
        self::assertStringContainsString('a1_status', $sql);
        self::assertStringContainsString('evidence_reference', $sql);
        self::assertStringContainsString('assessment_base_minor_units', $sql);
        self::assertStringContainsString('shared_household_confirmed', $sql);
    }

    private function migration(): string
    {
        $path = dirname(__DIR__, 4)
            . '/db/migrations/1256_payroll_person_statutory_evidence.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        return $sql;
    }
}
