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
        $conditional = file_get_contents(
            $root . '1351_payroll_jmhz_work_month_conditional_blocks.sql',
        );
        $conditionalContract = file_get_contents(
            $root . '1352_payroll_jmhz_work_month_conditional_contract.sql',
        );
        $eventGuard = file_get_contents(
            $root . '1353_payroll_jmhz_work_month_event_guard.sql',
        );
        $controlBindingPath = $root . '1354_payroll_jmhz_work_month_control_binding.sql';
        self::assertFileExists($controlBindingPath);
        $controlBinding = file_get_contents($controlBindingPath);
        $eventImmutability = file_get_contents(
            $root . '1355_payroll_time_month_event_immutability.sql',
        );
        $controlBindingGuard = file_get_contents(
            $root . '1356_payroll_jmhz_work_month_control_binding_guard.sql',
        );
        self::assertIsString($base);
        self::assertIsString($guards);
        self::assertIsString($range);
        self::assertIsString($binding);
        self::assertIsString($conditional);
        self::assertIsString($conditionalContract);
        self::assertIsString($eventGuard);
        self::assertIsString($controlBinding);
        self::assertIsString($eventImmutability);
        self::assertIsString($controlBindingGuard);

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
        self::assertStringContainsString('conditional_blocks_confirmed', $conditional);
        self::assertStringContainsString('unworked_total_millihours', $conditional);
        self::assertStringContainsString('employee_obstacle_paid_millihours', $conditional);
        self::assertStringContainsString('unworked_paid_millihours >= vacation_millihours', $conditional);
        self::assertStringContainsString('work_obstacles_occurred = 1', $conditional);
        self::assertStringContainsString(
            "derivation_version = 'jmhz-work-month-core.v1'",
            $conditionalContract,
        );
        self::assertStringContainsString(
            "derivation_version = 'jmhz-work-month.v2'",
            $conditionalContract,
        );
        self::assertStringContainsString(
            'CREATE TRIGGER trg_payroll_time_month_event_jmhz_guard',
            $eventGuard,
        );
        self::assertStringContainsString(
            'summary.time_month_revision_no = NEW.revision_no',
            $eventGuard,
        );
        self::assertStringContainsString(
            'summary.summary_sha256 = NEW.jmhz_work_summary_hash',
            $eventGuard,
        );
        self::assertStringContainsString('control_catalog_key', $controlBinding);
        self::assertStringContainsString('control_manifest_sha256', $controlBinding);
        self::assertSame(3, substr_count($eventImmutability, 'CREATE TRIGGER'));
        self::assertStringContainsString('expected_summary_count > 0', $eventImmutability);
        self::assertStringContainsString(
            'Payroll time month events are immutable',
            $eventImmutability,
        );
        self::assertStringContainsString(
            'control_catalog_key IS NOT NULL',
            $controlBindingGuard,
        );
        self::assertStringContainsString(
            'control_manifest_sha256 IS NOT NULL',
            $controlBindingGuard,
        );
        self::assertStringNotContainsString('ON DELETE CASCADE', $base);
    }
}
