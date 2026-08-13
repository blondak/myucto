<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollJmhzOrdinaryEvidenceMigrationTest extends TestCase
{
    public function testMigrationCreatesImmutableEncryptedEvidenceAndV5Preparation(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4)
            . '/db/migrations/1366_payroll_jmhz_ordinary_evidence.sql',
        );
        self::assertIsString($sql);
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_jmhz_ordinary_evidence_snapshots',
            $sql,
        );
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_jmhz_ordinary_evidence_idempotency_claims',
            $sql,
        );
        self::assertStringContainsString("snapshot_ciphertext LIKE 'enc:v2:%'", $sql);
        self::assertStringContainsString('trg_payroll_jmhz_ordinary_no_update', $sql);
        self::assertStringContainsString('trg_payroll_jmhz_ordinary_no_delete', $sql);
        self::assertStringContainsString('payroll_run_employments frozen_employment', $sql);
        foreach (['v1', 'v2', 'v3', 'v4', 'v5'] as $version) {
            self::assertStringContainsString("'jmhz-preparation-source.{$version}'", $sql);
        }
    }
}
