<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PayrollSubmissionPlatformMigrationTest extends TestCase
{
    public function testCorrectionLocksPredecessorBeforeCurrentObligation(): void
    {
        $path = dirname(__DIR__, 2)
            . '/src/Service/Payroll/Submission/PayrollSubmissionService.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        $methodStart = strpos($source, 'public function transition(');
        $methodEnd = strpos(
            $source,
            'public function importReceipt(',
            $methodStart === false ? 0 : $methodStart,
        );
        self::assertNotFalse($methodStart);
        self::assertNotFalse($methodEnd);
        $method = substr($source, $methodStart, $methodEnd - $methodStart);
        $predecessorLock = strpos(
            $method,
            '$predecessor = $this->repository->lockSubmission(',
        );
        $currentObligationLock = strpos(
            $method,
            '$obligation = $this->repository->lockObligation(',
        );
        self::assertNotFalse($predecessorLock);
        self::assertNotFalse($currentObligationLock);
        self::assertLessThan(
            $currentObligationLock,
            $predecessorLock,
            'Korekce nesmí při čekání na předchůdce držet aktuální povinnost.',
        );
    }

    public function testSubmissionPlatformIsTenantScopedIdempotentAndEvidenceReady(): void
    {
        $path = dirname(__DIR__, 3)
            . '/db/migrations/1279_payroll_submission_platform.sql';
        self::assertFileExists($path);
        $sql = (string) file_get_contents($path);

        foreach ([
            'payroll_agenda_matrix',
            'payroll_obligations',
            'payroll_submission_deadlines',
            'payroll_submissions',
            'payroll_submission_parts',
            'payroll_submission_artifacts',
            'payroll_submission_receipts',
            'payroll_submission_issues',
        ] as $table) {
            self::assertStringContainsString(
                "CREATE TABLE IF NOT EXISTS {$table}",
                $sql,
                $table,
            );
            self::assertStringContainsString(
                "UNIQUE KEY uq_{$table}_supplier_id (supplier_id, id)",
                $sql,
                $table,
            );
        }

        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, environment, obligation_id)',
            $sql,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, environment, submission_id)',
            $sql,
        );
        self::assertStringContainsString('idempotency_key_hash', $sql);
        self::assertStringContainsString('source_snapshot_hash', $sql);
        self::assertStringContainsString('artifact_sha256', $sql);
        self::assertStringContainsString('content_ciphertext', $sql);
        self::assertStringNotContainsString('content_plaintext', $sql);
        self::assertStringNotContainsString('password', $sql);
        self::assertStringContainsString(
            'Payroll submission artifacts are immutable',
            $sql,
        );
        self::assertStringContainsString(
            'Payroll submission receipts are immutable',
            $sql,
        );
        self::assertStringContainsString(
            "'fully_replaced','partially_replaced','standalone','unknown'",
            $sql,
        );
        self::assertStringContainsString(
            "'submitted','processing','accepted','partially_accepted','rejected'",
            $sql,
        );
        self::assertGreaterThanOrEqual(
            7,
            substr_count($sql, "environment           ENUM('production','test')"),
            'Prostředí musí být zmrazené napříč celým submission agregátem.',
        );
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, environment, submission_id, part_id)',
            $sql,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, environment, submission_id, artifact_id)',
            $sql,
        );
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_submissions_correlation',
            $sql,
        );
        self::assertStringContainsString(
            'Payroll submission correlation is immutable',
            $sql,
        );
        self::assertStringContainsString(
            'Payroll submission deadlines are immutable',
            $sql,
        );
        self::assertStringContainsString(
            'Payroll agenda matrix rows are immutable',
            $sql,
        );
        self::assertStringNotContainsString(
            'fk_payroll_submission_artifacts_creator'
                . "\n    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL",
            $sql,
        );
        self::assertStringNotContainsString(
            'fk_payroll_submission_receipts_importer'
                . "\n    FOREIGN KEY (imported_by) REFERENCES users (id) ON DELETE SET NULL",
            $sql,
        );
    }
}
