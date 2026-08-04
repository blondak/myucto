<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollEmployerPaymentIdentifierMigrationTest extends TestCase
{
    public function testLegacyCopyIsGuardedByDurableMarker(): void
    {
        $sql = $this->migration('1194_payroll_employer_payment_identifiers.sql');
        $marker = '1194_employer_payment_identifiers_v1';

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_data_migration_markers',
            $sql,
        );
        self::assertSame(
            3,
            substr_count($sql, "marker.migration_key = '{$marker}'"),
            'Každý ze tří legacy převodů musí být chráněný markerem.',
        );
        self::assertStringContainsString(
            "INSERT IGNORE INTO payroll_data_migration_markers (migration_key)\n"
            . "VALUES ('{$marker}')",
            $sql,
        );
        self::assertStringContainsString(
            'DROP COLUMN IF EXISTS health_insurance_payer_number',
            $sql,
        );
    }

    public function testCorrectiveMigrationOnlyRecordsMarker(): void
    {
        $sql = $this->migration(
            '1204_payroll_employer_payment_identifiers_marker.sql',
        );

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_data_migration_markers',
            $sql,
        );
        self::assertStringContainsString(
            'INSERT IGNORE INTO payroll_data_migration_markers',
            $sql,
        );
        self::assertStringNotContainsString('UPDATE payroll_', $sql);
        self::assertStringNotContainsString('UPDATE supplier', $sql);
    }

    public function testSsotCleanupRemovesCanonicalDuplicatesFromCompanySettings(): void
    {
        $sql = $this->migration(
            '1221_payroll_employer_identifier_ssot.sql',
        );

        self::assertStringContainsString('SET supplier.cssz_vsdp = NULL', $sql);
        self::assertStringContainsString('SET supplier.cssz_ossz_code = NULL', $sql);
        self::assertStringContainsString(
            'SET supplier.health_insurance_number = NULL',
            $sql,
        );
        self::assertSame(
            3,
            substr_count($sql, 'WHERE supplier.taxpayer_type = \'po\''),
        );
        self::assertSame(
            3,
            substr_count($sql, 'AND EXISTS ('),
            'Smazat se smí jen hodnota, která už má shodný kanonický mzdový záznam.',
        );
    }

    private function migration(string $file): string
    {
        $path = dirname(__DIR__, 4) . '/db/migrations/' . $file;
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        return str_replace("\r\n", "\n", $sql);
    }
}
