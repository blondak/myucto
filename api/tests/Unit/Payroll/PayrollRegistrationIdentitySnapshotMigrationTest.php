<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollRegistrationIdentitySnapshotMigrationTest extends TestCase
{
    public function testSnapshotIsTenantScopedEncryptedAndImmutable(): void
    {
        $path = dirname(__DIR__, 4)
            . '/db/migrations/'
            . '1288_payroll_registration_identity_snapshots.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_registration_identity_snapshots',
            $sql,
        );
        self::assertStringContainsString(
            '(supplier_id, environment, submission_id)',
            $sql,
        );
        self::assertStringContainsString(
            '(supplier_id, source_revision_id)',
            $sql,
        );
        self::assertStringContainsString(
            'snapshot_ciphertext LIKE \'enc:v2:%\'',
            $sql,
        );
        self::assertStringContainsString(
            'trg_payroll_registration_identity_snapshot_no_update',
            $sql,
        );
        self::assertStringContainsString(
            'trg_payroll_registration_identity_snapshot_no_delete',
            $sql,
        );
        self::assertStringNotContainsString('birth_number VARCHAR', $sql);
        self::assertStringNotContainsString('id_ppv VARCHAR', $sql);
        self::assertStringNotContainsString('full_name', $sql);
    }
}
