<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollJmhzPersonExternalIdentifierMigrationTest extends TestCase
{
    public function testPersonOicIsTenantAndEnvironmentScopedEncryptedEvidence(): void
    {
        $path = dirname(__DIR__, 4)
            . '/db/migrations/1357_payroll_jmhz_person_external_identifiers.sql';
        $scopePath = dirname(__DIR__, 4)
            . '/db/migrations/1358_payroll_jmhz_person_external_identifier_receipt_scope.sql';
        self::assertFileExists($path);
        self::assertFileExists($scopePath);
        $sql = file_get_contents($path);
        $scopeSql = file_get_contents($scopePath);
        self::assertIsString($sql);
        self::assertIsString($scopeSql);

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_person_external_ids',
            $sql,
        );
        self::assertStringContainsString("environment           ENUM('production','test')", $sql);
        self::assertStringContainsString("identifier_type       ENUM('ik_mpsv')", $sql);
        self::assertStringContainsString('(supplier_id, environment, employee_id, active_identifier_type)', $sql);
        self::assertStringContainsString('(supplier_id, environment, identifier_type, value_hash)', $sql);
        self::assertStringContainsString('REFERENCES payroll_employees (supplier_id, id)', $sql);
        self::assertStringContainsString(
            'REFERENCES payroll_submission_receipts (supplier_id, environment, id)',
            $sql,
        );
        self::assertStringContainsString('ON DELETE RESTRICT', $sql);
        self::assertStringContainsString("value_ciphertext LIKE 'enc:v2:%'", $sql);
        self::assertStringContainsString("source_kind = 'trusted_receipt'", $sql);
        self::assertStringNotContainsString('ON DELETE CASCADE', $sql);
        self::assertStringContainsString(
            'DROP FOREIGN KEY IF EXISTS fk_payroll_person_external_id_receipt',
            $scopeSql,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, environment, source_receipt_id)',
            $scopeSql,
        );
        self::assertStringContainsString(
            'REFERENCES payroll_submission_receipts (supplier_id, environment, id)',
            $scopeSql,
        );

        $guardPath = dirname(__DIR__, 4)
            . '/db/migrations/1359_payroll_jmhz_external_identifier_environment_guard.sql';
        self::assertFileExists($guardPath);
        $guardSql = file_get_contents($guardPath);
        self::assertIsString($guardSql);
        self::assertSame(2, substr_count($guardSql, 'CREATE TRIGGER'));
        self::assertSame(2, substr_count($guardSql, 'OLD.environment <=> NEW.environment'));
        self::assertStringContainsString(
            'Payroll person external identifier environment is immutable',
            $guardSql,
        );
        self::assertStringContainsString(
            'Payroll employment external identifier environment is immutable',
            $guardSql,
        );
    }
}
