<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollPersonProfileMigrationTest extends TestCase
{
    public function testFreshSchemaStoresContactsEncryptedAndIdentifiersTenantUnique(): void
    {
        $sql = $this->migration('1191_payroll_person_profile_history.sql');

        self::assertStringContainsString('contact_value_ciphertext VARCHAR(512) NOT NULL', $sql);
        self::assertStringContainsString('contact_value_hash       BINARY(32) NOT NULL', $sql);
        self::assertStringNotContainsString('contact_value VARCHAR', $sql);
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_identifier_tenant_hash',
            $sql,
        );
        self::assertStringContainsString(
            '(supplier_id, identifier_type, value_hash)',
            $sql,
        );
        self::assertStringNotContainsString("'personal_identifier'", $sql);
    }

    public function testCorrectiveMigrationIsIdempotentAndFailsClosedForPlaintext(): void
    {
        $sql = $this->migration('1193_payroll_person_profile_security.sql');

        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS payout_effective_on', $sql);
        self::assertStringContainsString(
            'ADD UNIQUE KEY IF NOT EXISTS uq_payroll_identifier_tenant_hash',
            $sql,
        );
        self::assertStringContainsString('SIGNAL SQLSTATE \'45000\'', $sql);
        self::assertStringContainsString(
            'DROP INDEX IF EXISTS uq_payroll_contact_value',
            $sql,
        );
        self::assertStringContainsString('DROP COLUMN IF EXISTS contact_value', $sql);
        self::assertStringContainsString(
            'MODIFY COLUMN contact_value_ciphertext VARCHAR(512) NOT NULL',
            $sql,
        );
    }

    private function migration(string $file): string
    {
        $path = dirname(__DIR__, 4) . '/db/migrations/' . $file;
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        return $sql;
    }
}
