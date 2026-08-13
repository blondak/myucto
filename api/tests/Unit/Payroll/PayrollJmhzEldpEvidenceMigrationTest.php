<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollJmhzEldpEvidenceMigrationTest extends TestCase
{
    public function testMigrationCreatesEncryptedImmutableEvidenceAndClaims(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 4) . '/db/migrations/1363_payroll_jmhz_eldp_evidence.sql');
        self::assertIsString($sql);
        self::assertStringContainsString('payroll_jmhz_eldp_evidence_snapshots', $sql);
        self::assertStringContainsString('payroll_jmhz_eldp_idempotency_claims', $sql);
        self::assertStringContainsString("snapshot_ciphertext LIKE 'enc:v2:%'", $sql);
        self::assertStringContainsString('ON DELETE RESTRICT', $sql);
        self::assertStringContainsString('current approved regular revision', $sql);
        self::assertStringContainsString('JOIN payroll_run_employments frozen_employment', $sql);
        self::assertStringContainsString('confirmation_fingerprint', $sql);
        self::assertStringContainsString('created_by                BIGINT UNSIGNED NOT NULL', $sql);
        self::assertStringContainsString('jmhz-preparation-source.v3', $sql);
        self::assertStringNotContainsString('assessment_base_czk', $sql);
        self::assertStringNotContainsString('insurance_days', $sql);
    }

    public function testHardeningMigrationPreservesFrozenGraphAndConfirmationGuards(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 4) . '/db/migrations/1364_payroll_jmhz_eldp_evidence_hardening.sql');
        self::assertIsString($sql);
        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS confirmation_fingerprint', $sql);
        self::assertStringContainsString('JOIN payroll_run_employments frozen_employment', $sql);
        self::assertStringContainsString('MODIFY COLUMN created_by BIGINT UNSIGNED NOT NULL', $sql);
        self::assertStringContainsString('NEW.confirmation_fingerprint <=> OLD.confirmation_fingerprint', $sql);
    }
}
