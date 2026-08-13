<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollJmhzPreparationSnapshotMigrationTest extends TestCase
{
    public function testPreparationSnapshotIsTenantScopedEncryptedAndImmutable(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4)
            . '/db/migrations/1360_payroll_jmhz_preparation_snapshots.sql',
        );
        self::assertIsString($sql);
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_jmhz_preparation_snapshots',
            $sql,
        );
        self::assertStringContainsString(
            "readiness_status          ENUM('blocked','source_ready')",
            $sql,
        );
        self::assertStringContainsString(
            "snapshot_ciphertext LIKE 'enc:v2:%'",
            $sql,
        );
        self::assertStringContainsString(
            'REFERENCES payroll_run_revisions (supplier_id, id, run_id)',
            $sql,
        );
        self::assertStringContainsString(
            'JMHZ preparation requires current approved revision',
            $sql,
        );
        self::assertSame(
            3,
            substr_count($sql, 'CREATE TRIGGER '),
        );
        self::assertSame(
            3,
            substr_count($sql, "SIGNAL SQLSTATE '45000'"),
        );
        self::assertStringNotContainsString('ON DELETE CASCADE', $sql);
        self::assertStringNotContainsString('snapshot_json', $sql);
    }


    public function testIdempotencyClaimsAreDurableSingleAssignmentAliases(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4)
            . '/db/migrations/1361_payroll_jmhz_preparation_idempotency_claims.sql',
        );
        self::assertIsString($sql);
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_jmhz_preparation_idempotency_claims',
            $sql,
        );
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_jmhz_preparation_claim_scope',
            $sql,
        );
        self::assertStringContainsString(
            'JMHZ preparation idempotency claim is single-assignment',
            $sql,
        );
        self::assertSame(2, substr_count($sql, 'CREATE TRIGGER '));
        self::assertStringNotContainsString('ON DELETE CASCADE', $sql);
    }
}
