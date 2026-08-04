<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollRegistrationIdentityMigrationTest extends TestCase
{
    public function testMigrationIsAdditiveTenantScopedAndKeepsSecretsEncrypted(): void
    {
        $path = dirname(__DIR__, 4)
            . '/db/migrations/1285_payroll_registration_identity_model.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        self::assertStringContainsString(
            'ADD COLUMN IF NOT EXISTS birth_country_code',
            $sql,
        );
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_employment_external_ids',
            $sql,
        );
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_identity_resolution_tasks',
            $sql,
        );
        self::assertStringContainsString(
            'value_ciphertext LIKE \'enc:v2:%\'',
            $sql,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, employment_id, employee_id)',
            $sql,
        );
        self::assertStringContainsString(
            '(supplier_id, environment, identifier_type, value_hash)',
            $sql,
        );
        self::assertStringNotContainsString('birth_number VARCHAR', $sql);
        self::assertStringNotContainsString('SUBSTRING_INDEX', $sql);
        self::assertStringNotContainsString('UPDATE payroll_person_identity_history', $sql);

        $receiptScopePath = dirname(__DIR__, 4)
            . '/db/migrations/'
            . '1286_payroll_registration_identity_receipt_scope.sql';
        $receiptScope = file_get_contents($receiptScopePath);
        self::assertIsString($receiptScope);
        self::assertStringContainsString(
            '(supplier_id, environment, source_receipt_id)',
            $receiptScope,
        );
        self::assertStringContainsString(
            'uq_payroll_submission_receipts_environment_id',
            $receiptScope,
        );
    }
}
