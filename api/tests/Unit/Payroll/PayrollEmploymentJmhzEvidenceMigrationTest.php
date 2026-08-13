<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollEmploymentJmhzEvidenceMigrationTest extends TestCase
{
    public function testEvidenceIsEffectiveDatedExplicitAndFailClosed(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4) . '/db/migrations/1345_payroll_employment_jmhz_core_evidence.sql',
        );
        self::assertIsString($sql);

        foreach ([
            'jmhz_workplace_municipality_code',
            'jmhz_workplace_country_code',
            'jmhz_apz_contribution_status',
            'jmhz_apz_instrument_code',
            'jmhz_functional_benefits_status',
            'jmhz_temporary_assignment_status',
        ] as $column) {
            self::assertStringContainsString("ADD COLUMN IF NOT EXISTS {$column}", $sql);
        }
        self::assertSame(3, substr_count($sql, "ENUM('unverified','no','yes')"));
        self::assertStringContainsString("DEFAULT 'unverified'", $sql);
        self::assertStringContainsString("REGEXP '^[0-9]{6}$'", $sql);
        self::assertStringContainsString("REGEXP '^[A-Z]{2}$'", $sql);
        self::assertStringContainsString("jmhz_apz_instrument_code IN ('1','2','3','4')", $sql);
        self::assertStringNotContainsString("UPDATE payroll_employment_terms", $sql);
    }
}
