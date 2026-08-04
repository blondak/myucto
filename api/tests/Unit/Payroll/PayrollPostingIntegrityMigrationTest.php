<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollPostingIntegrityMigrationTest extends TestCase
{
    public function testMigrationBindsBatchToRevisionOwnerAndProtectsHistory(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4)
            . '/db/migrations/1262_payroll_posting_integrity.sql',
        );
        self::assertIsString($sql);
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, revision_id, run_id)',
            $sql,
        );
        self::assertStringContainsString(
            'trg_payroll_posting_batch_immutable_update',
            $sql,
        );
        self::assertStringContainsString(
            'trg_payroll_posting_allocation_immutable_delete',
            $sql,
        );
        self::assertStringContainsString(
            "REGEXP '^[0-9]{3}[.A-Z0-9]{0,13}$'",
            $sql,
        );

        $corrections = file_get_contents(
            dirname(__DIR__, 4)
            . '/db/migrations/1263_payroll_posting_corrections_only.sql',
        );
        self::assertIsString($corrections);
        self::assertStringContainsString(
            "ENUM('prepared','posted','no_change')",
            $corrections,
        );
        self::assertStringNotContainsString("'reversed'", $corrections);

        $journalGuard = file_get_contents(
            dirname(__DIR__, 4)
            . '/db/migrations/1264_payroll_journal_integrity.sql',
        );
        self::assertIsString($journalGuard);
        self::assertStringContainsString(
            'trg_journal_payroll_batch_insert',
            $journalGuard,
        );
        self::assertStringContainsString(
            "NEW.source_type = 'payroll'",
            $journalGuard,
        );
        self::assertStringContainsString(
            'journal.source_id = NEW.revision_id',
            $journalGuard,
        );
        self::assertStringContainsString(
            'trg_journal_payroll_immutable_update',
            $journalGuard,
        );
    }
}
