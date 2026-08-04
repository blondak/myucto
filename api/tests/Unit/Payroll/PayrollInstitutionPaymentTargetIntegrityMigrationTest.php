<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Bootstrap;
use PHPUnit\Framework\TestCase;

final class PayrollInstitutionPaymentTargetIntegrityMigrationTest extends TestCase
{
    public function testMigrationProtectsVerificationAndRowVersion(): void
    {
        $sql = file_get_contents(
            Bootstrap::rootDir()
                . '/db/migrations/1275_payroll_institution_payment_target_integrity.sql',
        );
        self::assertIsString($sql);
        self::assertStringContainsString(
            'trg_payroll_institution_account_payment_insert',
            $sql,
        );
        self::assertStringContainsString(
            'trg_payroll_institution_account_payment_update',
            $sql,
        );
        self::assertStringContainsString(
            'NEW.row_version <= OLD.row_version',
            $sql,
        );
        self::assertStringContainsString(
            'NEW.verified_by IS NULL OR NEW.verified_on IS NULL',
            $sql,
        );
        self::assertStringContainsString(
            "OLD.bank_account_ciphertext <> 'pending:v1'",
            $sql,
        );
    }
}
