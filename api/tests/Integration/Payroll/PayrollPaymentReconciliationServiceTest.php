<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use DomainException;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentEvidenceReference;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationCommand;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReversalCommand;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollPaymentReconciliationServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $connection;
    private PDO $pdo;
    private PayrollPaymentReconciliationService $service;
    private int $supplierId;
    private int $allocationId;
    private int $statementId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $service = $container->get(PayrollPaymentReconciliationService::class);
        self::assertInstanceOf(PayrollPaymentReconciliationService::class, $service);

        $this->connection = $connection;
        $this->pdo = $connection->pdo();
        $this->service = $service;
        $this->pdo->beginTransaction();

        $sourceSupplierId = (int) $this
            ->query('SELECT MIN(id) FROM supplier')
            ->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $this->supplierId = $this->createIsolatedSupplier(
            $this->pdo,
            $sourceSupplierId,
        );
        [$revisionId, $employeeId] = $this->createApprovedRevision();
        $liabilityId = $this->insertLiability(
            $revisionId,
            $employeeId,
            100_000,
        );
        $this->allocationId = $this->insertAllocation(
            $liabilityId,
            'bank',
            100_000,
        );
        $this->statementId = $this->insertBankStatement();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    public function testBankPartialMatchesReplayAndPartialThenFullReversalUseExactMinorUnits(): void
    {
        $firstTransactionId = $this->insertBankTransaction(
            '2099-01-20',
            '-600.01',
            'first-part',
        );
        $first = $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                60_001,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $firstTransactionId,
                ),
                'bank-first',
                null,
            ),
        );
        self::assertSame(60_001, $first->amountMinor);
        self::assertSame('2099-01-20', $first->actualPaymentDate);
        self::assertFalse($first->replayed);

        $replay = $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                60_001,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $firstTransactionId,
                ),
                'bank-first',
                null,
            ),
        );
        self::assertSame($first->id, $replay->id);
        self::assertTrue($replay->replayed);
        try {
            $this->service->match(
                new PayrollPaymentReconciliationCommand(
                    $this->supplierId,
                    $this->allocationId,
                    60_000,
                    PayrollPaymentEvidenceReference::bank(
                        $this->statementId,
                        $firstTransactionId,
                    ),
                    'bank-first',
                    null,
                ),
            );
            self::fail('Změněný idempotentní replay musí být odmítnut.');
        } catch (DomainException $exception) {
            self::assertStringContainsString(
                'Idempotentní replay neodpovídá',
                $exception->getMessage(),
            );
        }

        $secondTransactionId = $this->insertBankTransaction(
            '2099-01-21',
            '-399.99',
            'second-part',
        );
        $second = $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                39_999,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $secondTransactionId,
                ),
                'bank-second',
                null,
            ),
        );

        $partialReversalTransactionId = $this->insertBankTransaction(
            '2099-01-25',
            '250.01',
            'partial-reversal',
        );
        $partial = $this->service->reverse(
            new PayrollPaymentReversalCommand(
                $this->supplierId,
                $first->id,
                25_001,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $partialReversalTransactionId,
                ),
                'bank-partial-reversal',
                null,
            ),
        );
        self::assertSame(-25_001, $partial->amountMinor);
        self::assertSame($first->id, $partial->sourceMatchId);

        $remainingReversalTransactionId = $this->insertBankTransaction(
            '2099-01-26',
            '350.00',
            'remaining-reversal',
        );
        $remaining = $this->service->reverse(
            new PayrollPaymentReversalCommand(
                $this->supplierId,
                $first->id,
                35_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $remainingReversalTransactionId,
                ),
                'bank-remaining-reversal',
                null,
            ),
        );
        self::assertSame(-35_000, $remaining->amountMinor);

        $fullReversalTransactionId = $this->insertBankTransaction(
            '2099-01-27',
            '399.99',
            'full-reversal',
        );
        $full = $this->service->reverse(
            new PayrollPaymentReversalCommand(
                $this->supplierId,
                $second->id,
                39_999,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $fullReversalTransactionId,
                ),
                'bank-full-reversal',
                null,
            ),
        );
        self::assertSame(-39_999, $full->amountMinor);
        self::assertSame(
            0,
            (int) $this->query(
                "SELECT SUM(amount_minor)
                   FROM payroll_payment_matches
                  WHERE supplier_id = {$this->supplierId}
                    AND allocation_id = {$this->allocationId}",
            )->fetchColumn(),
        );
    }

    public function testCashEvidenceSupportsIdempotentMatchAndFullReversal(): void
    {
        [$revisionId, $employeeId] = $this->createApprovedRevision('2099-02-01');
        $liabilityId = $this->insertLiability(
            $revisionId,
            $employeeId,
            20_000,
            'net-wage.cash.synthetic',
        );
        $allocationId = $this->insertAllocation(
            $liabilityId,
            'cash',
            20_000,
        );
        $cashDocumentId = $this->insertCashDocument();

        $match = $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $allocationId,
                20_000,
                PayrollPaymentEvidenceReference::cash($cashDocumentId),
                'cash-match',
                null,
            ),
        );
        self::assertSame('2099-02-10', $match->actualPaymentDate);
        self::assertSame('cash', $match->evidenceKind);

        $this->pdo->prepare(
            'UPDATE cash_documents SET status = "reversed" WHERE id = ?',
        )->execute([$cashDocumentId]);
        $reversal = $this->service->reverse(
            new PayrollPaymentReversalCommand(
                $this->supplierId,
                $match->id,
                20_000,
                PayrollPaymentEvidenceReference::cash($cashDocumentId),
                'cash-reversal',
                null,
            ),
        );
        self::assertSame(-20_000, $reversal->amountMinor);

        $replay = $this->service->reverse(
            new PayrollPaymentReversalCommand(
                $this->supplierId,
                $match->id,
                20_000,
                PayrollPaymentEvidenceReference::cash($cashDocumentId),
                'cash-reversal',
                null,
            ),
        );
        self::assertSame($reversal->id, $replay->id);
        self::assertTrue($replay->replayed);
    }

    public function testGloballyOwnedBankEvidenceAndCrossTenantEvidenceFailClosed(): void
    {
        $transactionId = $this->insertBankTransaction(
            '2099-01-20',
            '-1000.00',
            'owned',
        );
        $invoiceId = (int) $this
            ->query('SELECT id FROM invoices ORDER BY id LIMIT 1')
            ->fetchColumn();
        self::assertGreaterThan(
            0,
            $invoiceId,
            'Syntetická testovací databáze musí obsahovat výchozí fakturu.',
        );
        $invoiceSupplierId = (int) $this
            ->query("SELECT supplier_id FROM invoices WHERE id = {$invoiceId}")
            ->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO invoice_payments
                (supplier_id, invoice_id, paid_on, amount, currency, source,
                 bank_transaction_id)
             VALUES (?, ?, "2099-01-20", 1000.00, "CZK", "bank", ?)',
        )->execute([$invoiceSupplierId, $invoiceId, $transactionId]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'Bankovní pohyb už vlastní fakturační párování.',
        );
        $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                100_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $transactionId,
                ),
                'owned-bank',
                null,
            ),
        );
    }

    public function testBankEvidenceOwnedByPurchaseInvoiceMatchFailsClosed(): void
    {
        $transactionId = $this->insertBankTransaction(
            '2099-01-20',
            '-1000.00',
            'purchase-owned',
        );
        $purchaseInvoiceId = (int) $this
            ->query('SELECT id FROM purchase_invoices ORDER BY id LIMIT 1')
            ->fetchColumn();
        self::assertGreaterThan(
            0,
            $purchaseInvoiceId,
            'Syntetická testovací databáze musí obsahovat přijatou fakturu.',
        );
        $purchaseSupplierId = (int) $this->query(
            "SELECT supplier_id
               FROM purchase_invoices
              WHERE id = {$purchaseInvoiceId}",
        )->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO payment_matches
                (supplier_id, bank_transaction_id, purchase_invoice_id,
                 amount, match_type)
             VALUES (?, ?, ?, 1000.00, "manual")',
        )->execute([
            $purchaseSupplierId,
            $transactionId,
            $purchaseInvoiceId,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'Bankovní pohyb už vlastní fakturační párování.',
        );
        $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                100_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $transactionId,
                ),
                'purchase-owned-bank',
                null,
            ),
        );
    }

    public function testCashDocumentLinkedToInvoiceAndWrongTenantAreRejected(): void
    {
        [$revisionId, $employeeId] = $this->createApprovedRevision('2099-02-01');
        $liabilityId = $this->insertLiability(
            $revisionId,
            $employeeId,
            20_000,
            'net-wage.cash-owned.synthetic',
        );
        $allocationId = $this->insertAllocation(
            $liabilityId,
            'cash',
            20_000,
        );
        $cashDocumentId = $this->insertCashDocument();
        $invoiceId = (int) $this
            ->query('SELECT id FROM invoices ORDER BY id LIMIT 1')
            ->fetchColumn();
        self::assertGreaterThan(0, $invoiceId);
        $this->pdo->prepare(
            'UPDATE cash_documents
                SET purpose = "invoice_payment", invoice_id = ?
              WHERE id = ?',
        )->execute([$invoiceId, $cashDocumentId]);

        try {
            $this->service->match(
                new PayrollPaymentReconciliationCommand(
                    $this->supplierId,
                    $allocationId,
                    20_000,
                    PayrollPaymentEvidenceReference::cash($cashDocumentId),
                    'owned-cash',
                    null,
                ),
            );
            self::fail('Pokladní doklad faktury nesmí být použit pro mzdu.');
        } catch (DomainException $exception) {
            self::assertStringContainsString(
                'Pokladní doklad už vlastní fakturační párování.',
                $exception->getMessage(),
            );
        }

        $wrongSupplierId = $this->supplierId === 1 ? 2 : 1;
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Platební alokace nebyla nalezena');
        $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $wrongSupplierId,
                $allocationId,
                100_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $this->insertBankTransaction(
                        '2099-01-21',
                        '-1000.00',
                        'wrong-tenant',
                    ),
                ),
                'wrong-tenant',
                null,
            ),
        );
    }

    public function testBankTransactionOwnedByDirectBankReconciliationIsRejected(): void
    {
        $transactionId = $this->insertBankTransaction(
            '2099-01-20',
            '-1000.00',
            'bank-reconciliation',
        );
        $invoiceId = (int) $this
            ->query('SELECT id FROM invoices ORDER BY id LIMIT 1')
            ->fetchColumn();
        self::assertGreaterThan(0, $invoiceId);
        $this->pdo->prepare(
            'UPDATE bank_transactions
                SET matched_invoice_id = ?, match_status = "manual"
              WHERE id = ?',
        )->execute([$invoiceId, $transactionId]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'Bankovní pohyb už vlastní bankovní reconciliation.',
        );
        $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                100_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $transactionId,
                ),
                'bank-reconciliation-owned',
                null,
            ),
        );
    }

    public function testDatabasePreventsLaterTakeoverOfPayrollEvidence(): void
    {
        $transactionId = $this->insertBankTransaction(
            '2099-01-20',
            '-1000.00',
            'database-ownership',
        );
        $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $this->allocationId,
                100_000,
                PayrollPaymentEvidenceReference::bank(
                    $this->statementId,
                    $transactionId,
                ),
                'database-ownership',
                null,
            ),
        );

        $invoiceId = (int) $this
            ->query('SELECT id FROM invoices ORDER BY id LIMIT 1')
            ->fetchColumn();
        $invoiceSupplierId = (int) $this->query(
            "SELECT supplier_id FROM invoices WHERE id = {$invoiceId}",
        )->fetchColumn();
        $purchaseInvoiceId = (int) $this
            ->query('SELECT id FROM purchase_invoices ORDER BY id LIMIT 1')
            ->fetchColumn();
        $purchaseSupplierId = (int) $this->query(
            "SELECT supplier_id
               FROM purchase_invoices
              WHERE id = {$purchaseInvoiceId}",
        )->fetchColumn();
        self::assertGreaterThan(0, $invoiceId);
        self::assertGreaterThan(0, $purchaseInvoiceId);

        $bankTakeovers = [
            function () use (
                $invoiceSupplierId,
                $invoiceId,
                $transactionId,
            ): void {
                $this->pdo->prepare(
                    'INSERT INTO invoice_payments
                        (supplier_id, invoice_id, paid_on, amount, currency,
                         source, bank_transaction_id)
                     VALUES (?, ?, "2099-01-20", 1000.00, "CZK",
                             "bank", ?)',
                )->execute([
                    $invoiceSupplierId,
                    $invoiceId,
                    $transactionId,
                ]);
            },
            function () use (
                $purchaseSupplierId,
                $purchaseInvoiceId,
                $transactionId,
            ): void {
                $this->pdo->prepare(
                    'INSERT INTO payment_matches
                        (supplier_id, bank_transaction_id,
                         purchase_invoice_id, amount, match_type)
                     VALUES (?, ?, ?, 1000.00, "manual")',
                )->execute([
                    $purchaseSupplierId,
                    $transactionId,
                    $purchaseInvoiceId,
                ]);
            },
            function () use ($invoiceId, $transactionId): void {
                $this->pdo->prepare(
                    'UPDATE bank_transactions
                        SET matched_invoice_id = ?, match_status = "manual"
                      WHERE id = ?',
                )->execute([$invoiceId, $transactionId]);
            },
        ];
        foreach ($bankTakeovers as $takeover) {
            try {
                $takeover();
                self::fail(
                    'Bankovní důkaz mzdy nesmí později převzít fakturace.',
                );
            } catch (\PDOException $exception) {
                self::assertStringContainsString(
                    'Payroll bank evidence is already owned',
                    $exception->getMessage(),
                );
            }
        }

        [$revisionId, $employeeId] = $this->createApprovedRevision(
            '2099-02-01',
        );
        $liabilityId = $this->insertLiability(
            $revisionId,
            $employeeId,
            20_000,
            'net-wage.cash-database-ownership.synthetic',
        );
        $allocationId = $this->insertAllocation(
            $liabilityId,
            'cash',
            20_000,
        );
        $cashDocumentId = $this->insertCashDocument();
        $this->service->match(
            new PayrollPaymentReconciliationCommand(
                $this->supplierId,
                $allocationId,
                20_000,
                PayrollPaymentEvidenceReference::cash($cashDocumentId),
                'cash-database-ownership',
                null,
            ),
        );

        try {
            $this->pdo->prepare(
                'UPDATE cash_documents
                    SET purpose = "invoice_payment", invoice_id = ?
                  WHERE id = ?',
            )->execute([$invoiceId, $cashDocumentId]);
            self::fail(
                'Pokladní důkaz mzdy nesmí později převzít fakturace.',
            );
        } catch (\PDOException $exception) {
            self::assertStringContainsString(
                'Payroll cash evidence is already owned',
                $exception->getMessage(),
            );
        }
    }

    /** @return array{int,int} */
    private function createApprovedRevision(
        string $periodStart = '2099-01-01',
    ): array {
        $this->pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická platební osoba", "employee", 1)',
        )->execute([$this->supplierId]);
        $employeeId = (int) $this->pdo->lastInsertId();

        $paymentDate = substr($periodStart, 0, 8) . '10';
        $this->pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status)
             VALUES (?, ?, ?, "approved")',
        )->execute([$this->supplierId, $periodStart, $paymentDate]);
        $runId = (int) $this->pdo->lastInsertId();

        $snapshot = '{"schema":"synthetic-payroll-result.v1"}';
        $snapshotHash = hash('sha256', $snapshot);
        $this->pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "approved", "synthetic-payment.v1",
                     ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $snapshot,
            $snapshotHash,
            $snapshot,
            $snapshotHash,
            hash(
                'sha256',
                "synthetic-approved-revision-{$periodStart}",
                true,
            ),
        ]);
        $revisionId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json,
                 result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")',
        )->execute([
            $this->supplierId,
            $revisionId,
            $employeeId,
            $snapshot,
            $snapshotHash,
        ]);

        return [$revisionId, $employeeId];
    }

    private function insertLiability(
        int $revisionId,
        int $employeeId,
        int $amountMinor,
        string $reference = 'net-wage.synthetic',
    ): int {
        $snapshot = '{"schema":"synthetic-liability.v1"}';
        $this->pdo->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 currency_code, amount_minor, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, ?, "net_wage", "outgoing", ?,
                     "2099-01-10", "CZK", ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $revisionId,
            $employeeId,
            $reference,
            'recipient:synthetic',
            $amountMinor,
            $snapshot,
            hash('sha256', $snapshot),
            hash('sha256', "liability-{$reference}", true),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertAllocation(
        int $liabilityId,
        string $channel,
        int $amountMinor,
    ): int {
        $reference = "{$channel}-{$liabilityId}";
        $this->pdo->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 direction, planned_payment_date, currency_code,
                 payer_reference, declared_total_minor, declared_item_count,
                 snapshot_ciphertext, snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, "manual", "outgoing", "2099-01-10", "CZK",
                     "payer:synthetic", ?, 1, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            "batch-{$reference}",
            $channel,
            $amountMinor,
            'enc:v2:synthetic-batch',
            hash('sha256', "batch-{$reference}"),
            hash('sha256', "batch-{$reference}", true),
        ]);
        $batchId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO payroll_payment_items
                (supplier_id, batch_id, item_reference, recipient_reference,
                 amount_minor, instruction_ciphertext, instruction_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, "recipient:synthetic", ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $batchId,
            "item-{$reference}",
            $amountMinor,
            'enc:v2:synthetic-instruction',
            hash('sha256', "item-{$reference}"),
            hash('sha256', "item-{$reference}", true),
        ]);
        $itemId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO payroll_payment_allocations
                (supplier_id, item_id, liability_id, amount_minor,
                 idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $itemId,
            $liabilityId,
            $amountMinor,
            hash('sha256', "allocation-{$reference}", true),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertBankStatement(): int
    {
        $this->pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number,
                 bank_code, currency, statement_date)
             VALUES (?, "synthetic-payroll-reconciliation.gpc", ?,
                     "1000000005", "0100", "CZK", "2099-01-31")',
        )->execute([
            $this->supplierId,
            hash(
                'sha256',
                "synthetic-payroll-reconciliation-{$this->supplierId}",
            ),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertBankTransaction(
        string $postedAt,
        string $amount,
        string $reference,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description,
                 import_fingerprint)
             VALUES (?, ?, ?, "CZK", ?, ?)',
        )->execute([
            $this->statementId,
            $postedAt,
            $amount,
            "Syntetická mzdová platba {$reference}",
            hash(
                'sha256',
                "synthetic-bank-{$this->supplierId}-{$reference}",
            ),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertCashDocument(): int
    {
        $this->pdo->prepare(
            'INSERT INTO cash_registers
                (supplier_id, name, currency_code, account_code)
             VALUES (?, "Syntetická mzdová pokladna", "CZK", "211999")',
        )->execute([$this->supplierId]);
        $registerId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number,
                 issue_date, description, total_amount, currency_code,
                 counter_account_code, status)
             VALUES (?, ?, "out", "other", ?, "2099-02-10",
                     "Syntetická hotovostní mzda", 200.00, "CZK",
                     "331", "posted")',
        )->execute([
            $this->supplierId,
            $registerId,
            "VPD-SYNTHETIC-{$this->supplierId}",
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function query(string $sql): \PDOStatement
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return $statement;
    }
}
