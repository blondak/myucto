<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use PDO;

/**
 * Seed jedné mzdové platby až po `payroll_payment_matches` — řádek, který drží
 * cizí klíč RESTRICT na bankovní výpis, bankovní transakci i pokladní doklad.
 *
 * Právě tenhle řádek je celý nález: mazací routy mimo mzdy o něm nevěděly a
 * padaly na něm syrovou databázovou hláškou. Cesta k němu vede přes celý řetěz
 * (osoba → běh → revize → závazek → dávka → položka → alokace), protože každý
 * článek je vynucený cizím klíčem — zkratka neexistuje.
 *
 * Postup převzatý z {@see \MyInvoice\Tests\Integration\Payroll\PayrollPaymentLedgerSchemaTest}.
 */
trait PayrollPaymentEvidenceTrait
{
    /**
     * Otisk výsledku revize. `payroll_generated_documents` má trigger, který
     * vyžaduje shodu s `payroll_run_revisions.result_snapshot_hash` — seed
     * dokladu ho tedy musí znát, ne si vymyslet vlastní.
     */
    protected function payrollResultSnapshotHash(): string
    {
        return hash('sha256', '{"schema":"fkguard-payroll-result.v1"}');
    }

    /** @return array{0:int,1:int} [revisionId, employeeId] */
    protected function seedApprovedRevision(PDO $pdo, int $supplierId, string $seed): array
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)'
        )->execute([$supplierId, "Testovaná osoba {$seed}"]);
        $employeeId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status)
             VALUES (?, "2099-01-01", "2099-01-10", "approved")'
        )->execute([$supplierId]);
        $runId = (int) $pdo->lastInsertId();

        $snapshot = '{"schema":"fkguard-payroll-result.v1"}';
        $snapshotHash = hash('sha256', $snapshot);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "approved", "fkguard-payment.v1", ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $supplierId,
            $runId,
            str_repeat('a', 64),
            $snapshot,
            $snapshotHash,
            $snapshot,
            $snapshotHash,
            hash('sha256', "fkguard-revision-{$seed}", true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json, result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")'
        )->execute([$supplierId, $revisionId, $employeeId, $snapshot, $snapshotHash]);

        return [$revisionId, $employeeId];
    }

    /** Závazek → dávka → položka → alokace; vrací id alokace. */
    protected function seedAllocation(PDO $pdo, int $supplierId, string $seed, string $channel): int
    {
        [$revisionId, $employeeId] = $this->seedApprovedRevision($pdo, $supplierId, $seed);

        $snapshot = '{"schema":"fkguard-liability.v1"}';
        $pdo->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id, liability_reference, liability_kind,
                 direction, recipient_reference, due_on, currency_code, amount_minor,
                 source_snapshot_json, source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, ?, "net_wage", "outgoing", "recipient:fkguard",
                     "2099-01-10", "CZK", 100000, ?, ?, ?)'
        )->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            "net-wage.{$seed}",
            $snapshot,
            hash('sha256', $snapshot),
            hash('sha256', "fkguard-liability-{$seed}", true),
        ]);
        $liabilityId = (int) $pdo->lastInsertId();

        $batchKey = "fkguard-batch-{$seed}";
        $pdo->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format, direction,
                 planned_payment_date, currency_code, payer_reference, declared_total_minor,
                 declared_item_count, snapshot_ciphertext, snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, "manual", "outgoing", "2099-01-10", "CZK",
                     "payer:fkguard", 100000, 1, "enc:v2:fkguard", ?, ?)'
        )->execute([
            $supplierId,
            $batchKey,
            $channel,
            hash('sha256', $batchKey),
            hash('sha256', $batchKey, true),
        ]);
        $batchId = (int) $pdo->lastInsertId();

        $itemKey = "fkguard-item-{$seed}";
        $pdo->prepare(
            'INSERT INTO payroll_payment_items
                (supplier_id, batch_id, item_reference, recipient_reference, amount_minor,
                 instruction_ciphertext, instruction_hash, idempotency_key_hash)
             VALUES (?, ?, ?, "recipient:fkguard", 100000, "enc:v2:fkguard", ?, ?)'
        )->execute([
            $supplierId,
            $batchId,
            $itemKey,
            hash('sha256', $itemKey),
            hash('sha256', $itemKey, true),
        ]);
        $itemId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_payment_allocations
                (supplier_id, item_id, liability_id, amount_minor, idempotency_key_hash)
             VALUES (?, ?, ?, 100000, ?)'
        )->execute([
            $supplierId,
            $itemId,
            $liabilityId,
            hash('sha256', "fkguard-allocation-{$seed}", true),
        ]);

        return (int) $pdo->lastInsertId();
    }

    protected function seedBankStatement(PDO $pdo, int $supplierId, string $seed, string $source = 'gpc'): int
    {
        $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number, bank_code, currency,
                 statement_date, source)
             VALUES (?, ?, ?, "1000000005", "0100", "CZK", "2099-01-31", ?)'
        )->execute([
            $supplierId,
            "fkguard-{$seed}.gpc",
            hash('sha256', "fkguard-statement-{$seed}"),
            $source,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Odchozí pohyb (záporná částka) — `trg_payroll_payment_match_validate_insert`
     * porovnává směr pohybu se směrem závazku a mzdový závazek je `outgoing`.
     */
    protected function seedBankTransaction(PDO $pdo, int $statementId, string $seed): int
    {
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description, import_fingerprint)
             VALUES (?, "2099-01-15", -1000.00, "CZK", ?, ?)'
        )->execute([
            $statementId,
            "Testovací pohyb {$seed}",
            hash('sha256', "fkguard-transaction-{$seed}"),
        ]);

        return (int) $pdo->lastInsertId();
    }

    protected function seedBankPaymentMatch(
        PDO $pdo,
        int $supplierId,
        int $allocationId,
        int $statementId,
        int $transactionId,
        string $seed,
    ): int {
        $pdo->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, allocation_id, event_kind, amount_minor,
                 bank_statement_id, bank_transaction_id, idempotency_key_hash)
             VALUES (?, ?, "matched", 100000, ?, ?, ?)'
        )->execute([
            $supplierId,
            $allocationId,
            $statementId,
            $transactionId,
            hash('sha256', "fkguard-bank-match-{$seed}", true),
        ]);

        return (int) $pdo->lastInsertId();
    }

    protected function seedCashRegister(PDO $pdo, int $supplierId, string $seed): int
    {
        $pdo->prepare(
            'INSERT INTO cash_registers (supplier_id, name, currency_code, account_code)
             VALUES (?, ?, "CZK", "211999")'
        )->execute([$supplierId, "Testovací pokladna {$seed}"]);

        return (int) $pdo->lastInsertId();
    }

    protected function seedCashDocument(
        PDO $pdo,
        int $supplierId,
        int $registerId,
        string $seed,
        string $status = 'posted',
    ): int {
        $pdo->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date,
                 description, total_amount, currency_code, counter_account_code, status)
             VALUES (?, ?, "out", "other", ?, "2099-01-22", ?, 1000.00, "CZK", "331", ?)'
        )->execute([
            $supplierId,
            $registerId,
            "VPD-FKGUARD-{$seed}",
            "Testovací hotovostní výdej {$seed}",
            $status,
        ]);

        return (int) $pdo->lastInsertId();
    }

    protected function seedCashPaymentMatch(
        PDO $pdo,
        int $supplierId,
        int $allocationId,
        int $cashDocumentId,
        string $seed,
    ): int {
        $pdo->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, allocation_id, event_kind, amount_minor, cash_document_id,
                 actual_payment_date, idempotency_key_hash)
             VALUES (?, ?, "matched", 100000, ?, NULL, ?)'
        )->execute([
            $supplierId,
            $allocationId,
            $cashDocumentId,
            hash('sha256', "fkguard-cash-match-{$seed}", true),
        ]);

        return (int) $pdo->lastInsertId();
    }
}
