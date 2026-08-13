<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollJmhzPreparationSourceV4MigrationTest extends TestCase
{
    public function testMigrationPreservesHistoricalBuildersAndAddsV4(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4)
            . '/db/migrations/1365_payroll_jmhz_preparation_source_v4.sql',
        );
        self::assertIsString($sql);
        self::assertStringContainsString(
            'DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_preparation_builder',
            $sql,
        );
        foreach (['v1', 'v2', 'v3', 'v4'] as $version) {
            self::assertStringContainsString(
                "'jmhz-preparation-source.{$version}'",
                $sql,
            );
        }
    }
}
