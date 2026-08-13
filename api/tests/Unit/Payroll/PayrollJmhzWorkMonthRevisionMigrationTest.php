<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollJmhzWorkMonthRevisionMigrationTest extends TestCase
{
    public function testCoreRevisionIsTenantScopedImmutableAndBoundToApproval(): void
    {
        $root = dirname(__DIR__, 4) . '/db/migrations/';
        $base = file_get_contents($root . '1347_payroll_jmhz_work_month_core_revisions.sql');
        $guards = file_get_contents($root . '1348_payroll_jmhz_work_month_revision_guards.sql');
        $range = file_get_contents($root . '1349_payroll_jmhz_work_month_weekly_range.sql');
        $binding = file_get_contents($root . '1350_payroll_jmhz_work_month_spec_binding.sql');
        self::assertIsString($base);
        self::assertIsString($guards);
        self::assertIsString($range);
        self::assertIsString($binding);

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_jmhz_work_month_revisions',
            $base,
        );
        self::assertStringContainsString(
            '(supplier_id, time_month_id, time_month_revision_no)',
            $base,
        );
        self::assertStringContainsString(
            'REFERENCES payroll_time_months (supplier_id, id)',
            $base,
        );
        self::assertStringContainsString('ON DELETE RESTRICT', $base);
        self::assertStringContainsString('weekly_work_centihours     INT UNSIGNED', $base);
        self::assertStringContainsString('source_snapshot_json', $base);
        self::assertStringContainsString('jmhz_work_summary_revision_id', $base);
        self::assertStringContainsString('spec_manifest_sha256', $base);
        self::assertStringContainsString('scenario_manifest_sha256', $base);
        self::assertSame(2, substr_count($base, 'CREATE TRIGGER IF NOT EXISTS'));
        self::assertStringContainsString(
            'CREATE TRIGGER trg_payroll_jmhz_work_month_insert_guard',
            $guards,
        );
        self::assertStringContainsString("month_row.status = 'approved'", $guards);
        self::assertStringContainsString(
            'month_row.revision_no = NEW.time_month_revision_no',
            $guards,
        );
        self::assertStringContainsString(
            'MODIFY COLUMN weekly_work_centihours INT UNSIGNED NOT NULL',
            $range,
        );
        self::assertStringContainsString(
            'REFERENCES payroll_jmhz_spec_packages (id)',
            $binding,
        );
        self::assertStringNotContainsString('ON DELETE CASCADE', $base);
    }
}
