<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollJmhzTransportAttemptsMigrationTest extends TestCase
{
    private function sql(): string
    {
        $path = dirname(__DIR__, 4)
            . '/db/migrations/1372_payroll_jmhz_transport_attempts.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        return $sql;
    }

    public function testLedgerIsTenantAndEnvironmentScoped(): void
    {
        $sql = $this->sql();

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_submission_transport_attempts',
            $sql,
        );
        self::assertStringContainsString(
            "environment           ENUM('production','test') NOT NULL",
            $sql,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, environment, submission_id)'
                . "\n    REFERENCES payroll_submissions (supplier_id, environment, id)",
            $sql,
        );
        self::assertStringContainsString(
            'uq_payroll_transport_attempts_environment_id',
            $sql,
        );
        self::assertStringContainsString(
            'uq_payroll_transport_attempts_order',
            $sql,
        );
    }

    public function testAttemptStatusesAndIdempotencyMatchTheTransportContract(): void
    {
        $sql = $this->sql();

        self::assertStringContainsString(
            "'prepared','sent','awaiting_protocol','completed','failed','expired'",
            $sql,
        );
        self::assertStringContainsString(
            'idempotency_key_hash  BINARY(32) NOT NULL',
            $sql,
        );
        self::assertStringContainsString(
            "UNIQUE KEY uq_payroll_transport_attempts_idempotency\n    (idempotency_key_hash)",
            $sql,
        );
        foreach ([
            'correlation_reference',
            'request_sha256',
            'response_http_status',
            'error_code',
            'error_message',
            'next_retry_at',
            'row_version',
        ] as $column) {
            self::assertStringContainsString($column, $sql);
        }
    }

    public function testEvidenceIsAppendOnlyAndIdentityIsImmutable(): void
    {
        $sql = $this->sql();

        self::assertSame(3, substr_count($sql, 'CREATE TRIGGER '));
        self::assertStringContainsString(
            'trg_payroll_transport_attempts_no_delete',
            $sql,
        );
        self::assertStringContainsString(
            'transport attempt identity is immutable',
            $sql,
        );
        self::assertStringContainsString(
            'transport attempt row_version must advance by one',
            $sql,
        );
        self::assertStringContainsString(
            'transport attempt correlation reference is single-assignment',
            $sql,
        );
        self::assertStringContainsString(
            'transport attempt channel must match its submission',
            $sql,
        );
        // Ledger, který smí zapomenout odeslání, není ledger, ale stavová
        // proměnná: bez těchhle dvou strážců šlo pokus vrátit z 'sent' zpět
        // na 'prepared' a vynulovat sent_at.
        self::assertStringContainsString(
            'transport attempt sent_at is single-assignment',
            $sql,
        );
        self::assertStringContainsString(
            'transport attempt cannot return to prepared',
            $sql,
        );
    }

    public function testFailureAndTrackingInvariantsAreEnforcedInSchema(): void
    {
        $sql = $this->sql();

        foreach ([
            'chk_payroll_transport_attempts_request',
            'chk_payroll_transport_attempts_correlation',
            'chk_payroll_transport_attempts_error_code',
            'chk_payroll_transport_attempts_failure',
            'chk_payroll_transport_attempts_sent',
            'chk_payroll_transport_attempts_completed',
            'chk_payroll_transport_attempts_timeline',
            'chk_payroll_transport_attempts_tracking',
        ] as $constraint) {
            self::assertStringContainsString($constraint, $sql);
        }
        self::assertStringNotContainsString('ON DELETE CASCADE', $sql);
    }
}
