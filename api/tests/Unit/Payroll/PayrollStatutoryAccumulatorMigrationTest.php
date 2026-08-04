<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollStatutoryAccumulatorMigrationTest extends TestCase
{
    public function testMigrationKeepsOpeningsAndApprovedResultsSeparateAndAppendOnly(): void
    {
        $sql = $this->migration('1258_payroll_statutory_accumulators.sql');

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_statutory_accumulator_openings',
            $sql,
        );
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_statutory_accumulator_entries',
            $sql,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, revision_id, employee_id)',
            $sql,
        );
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_statutory_entry_revision',
            $sql,
        );
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_statutory_entry_replacement',
            $sql,
        );
        self::assertStringContainsString(
            'Payroll statutory accumulator openings are append-only',
            $sql,
        );
        self::assertStringContainsString(
            'Payroll statutory accumulator entries are append-only',
            $sql,
        );
        self::assertStringNotContainsString('ON DUPLICATE KEY UPDATE', $sql);
    }

    private function migration(string $file): string
    {
        $path = dirname(__DIR__, 4) . '/db/migrations/' . $file;
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        return $sql;
    }
}
