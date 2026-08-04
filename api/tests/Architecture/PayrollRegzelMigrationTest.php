<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PayrollRegzelMigrationTest extends TestCase
{
    public function testRegzelSnapshotsAreTenantScopedEncryptedAndImmutable(): void
    {
        $path = dirname(__DIR__, 3)
            . '/db/migrations/1284_payroll_regzel_backend_core.sql';
        self::assertFileExists($path);
        $sql = (string) file_get_contents($path);

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_regzel_employer_profiles',
            $sql,
        );
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_regzel_payload_snapshots',
            $sql,
        );
        self::assertStringContainsString(
            "environment              ENUM('production','test')",
            $sql,
        );
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_regzel_snapshot_idempotency',
            $sql,
        );
        self::assertStringContainsString(
            'supplier_id, environment, idempotency_key_hash',
            $sql,
        );
        self::assertStringContainsString('snapshot_ciphertext', $sql);
        self::assertStringNotContainsString('snapshot_plaintext', $sql);
        self::assertStringContainsString('source_snapshot_hash', $sql);
        self::assertStringContainsString('xml_sha256', $sql);
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, office_id)',
            $sql,
        );
        self::assertStringContainsString(
            'Payroll REGZEL payload snapshots are immutable',
            $sql,
        );
    }
}
