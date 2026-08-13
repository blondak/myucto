<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollJmhzSpecCodebookMigrationTest extends TestCase
{
    public function testRegistryIsGlobalVersionedAndAppendOnly(): void
    {
        $path = dirname(__DIR__, 4) . '/db/migrations/1334_payroll_jmhz_spec_codebooks.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        foreach ([
            'payroll_jmhz_spec_packages',
            'payroll_jmhz_dictionary_attributes',
            'payroll_jmhz_codebooks',
            'payroll_jmhz_codebook_entries',
        ] as $table) {
            self::assertStringContainsString("CREATE TABLE IF NOT EXISTS {$table}", $sql);
        }
        self::assertDoesNotMatchRegularExpression('/^\s+supplier_id\s+/m', $sql);
        self::assertStringContainsString("source_kind IN ('embedded', 'external_reference')", $sql);
        self::assertStringContainsString('COLLATE utf8mb4_bin NOT NULL', $sql);
        self::assertSame(11, substr_count($sql, 'SIGNAL SQLSTATE'));
        self::assertSame(11, substr_count($sql, 'CREATE TRIGGER IF NOT EXISTS'));
        self::assertStringContainsString('ON UPDATE RESTRICT ON DELETE RESTRICT', $sql);
    }

    public function testMarkerFidelityUpgradeNeverSilentlyDropsCodebookReferences(): void
    {
        $path = dirname(__DIR__, 4) . '/db/migrations/1335_payroll_jmhz_spec_marker_fidelity.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        self::assertStringContainsString('data_type_refinement', $sql);
        self::assertStringContainsString('regzec_xsd_mapping', $sql);
        self::assertStringContainsString('employer_registration_marker', $sql);
        self::assertStringContainsString('employee_registration_marker', $sql);
        self::assertStringContainsString('monthly_marker VARCHAR(64)', $sql);
        self::assertStringContainsString('fk_payroll_jmhz_attribute_codebook', $sql);
        self::assertDoesNotMatchRegularExpression('/SET\s+codebook_key\s*=\s*NULL/i', $sql);
    }
}
