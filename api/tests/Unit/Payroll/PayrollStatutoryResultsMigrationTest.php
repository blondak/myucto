<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollStatutoryResultsMigrationTest extends TestCase
{
    public function testMigrationCreatesImmutableThreeLevelResultGraph(): void
    {
        $sql = $this->migration();

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_statutory_results',
            $sql,
        );
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_statutory_person_results',
            $sql,
        );
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_statutory_relationship_results',
            $sql,
        );
        self::assertStringContainsString(
            "'social_insurance','health_insurance','income_tax','net_pay'",
            $sql,
        );
        self::assertStringContainsString(
            "ENUM('calculated','manual_review','error')",
            $sql,
        );
        self::assertStringContainsString('ruleset_id', $sql);
        self::assertStringContainsString('ruleset_hash', $sql);
        self::assertStringContainsString('input_snapshot_json', $sql);
        self::assertStringContainsString('result_snapshot_json', $sql);
        self::assertStringContainsString('result_set_hash', $sql);
        self::assertStringContainsString(
            'REFERENCES payroll_run_persons',
            $sql,
        );
        self::assertStringContainsString(
            'REFERENCES payroll_run_employments',
            $sql,
        );
        self::assertStringContainsString(
            'REFERENCES payroll_employments (supplier_id, id, employee_id)',
            $sql,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT',
            $sql,
        );
        self::assertSame(6, substr_count($sql, 'SIGNAL SQLSTATE \'45000\''));
    }

    public function testMigrationUsesOnlyIdempotentSchemaOperations(): void
    {
        $sql = $this->migration();

        self::assertStringNotContainsString('CREATE TABLE payroll_', $sql);
        self::assertStringNotContainsString('CREATE TRIGGER trg_', $sql);
        self::assertStringContainsString('ADD UNIQUE KEY IF NOT EXISTS', $sql);
        self::assertSame(3, substr_count($sql, 'CREATE TABLE IF NOT EXISTS'));
        self::assertSame(6, substr_count($sql, 'CREATE TRIGGER IF NOT EXISTS'));
    }

    private function migration(): string
    {
        $path = dirname(__DIR__, 4)
            . '/db/migrations/1255_payroll_statutory_results.sql';
        $sql = @file_get_contents($path);
        self::assertIsString($sql, 'Migrace 1255 musí existovat.');

        return $sql;
    }
}
