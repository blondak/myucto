<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollEmploymentJmhzExternalCodebookMigrationTest extends TestCase
{
    public function testProvenanceColumnsArePairedAndFailClosed(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4)
                . '/db/migrations/1346_payroll_employment_jmhz_external_codebook_provenance.sql',
        );
        self::assertIsString($sql);
        self::assertStringContainsString(
            'ADD COLUMN IF NOT EXISTS jmhz_external_codebook_overlay_key',
            $sql,
        );
        self::assertStringContainsString(
            'ADD COLUMN IF NOT EXISTS jmhz_external_codebook_manifest_sha256',
            $sql,
        );
        self::assertStringContainsString("REGEXP '^[0-9a-f]{64}$'", $sql);
        self::assertStringContainsString('DROP CONSTRAINT IF EXISTS', $sql);
        self::assertStringNotContainsString('UPDATE payroll_employment_terms', $sql);
    }
}
