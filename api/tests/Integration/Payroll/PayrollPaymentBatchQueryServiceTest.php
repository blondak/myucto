<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payment\CzechBankAccountValidator;
use MyInvoice\Service\Payment\IbanValidator;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentBatchQueryService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollPaymentBatchQueryServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollPaymentBatchQueryService $queries;
    private int $supplierId;
    private int $otherSupplierId;

    protected function setUp(): void
    {
        $connection = Bootstrap::buildContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->db = $connection;
        $pdo = $connection->pdo();
        $source = $pdo->query('SELECT MIN(id) FROM supplier');
        self::assertInstanceOf(\PDOStatement::class, $source);
        $sourceId = (int) $source->fetchColumn();
        self::assertGreaterThan(0, $sourceId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceId);
        $this->otherSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceId,
        );
        $this->queries = new PayrollPaymentBatchQueryService(
            $connection,
            new CzechBankAccountValidator(),
            new IbanValidator(),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testListsOnlyValidMaskedPayerOptions(): void
    {
        $this->insertCurrency(
            $this->supplierId,
            'CZK',
            '1000000005',
            '0100',
            null,
            'Syntetická banka',
        );
        $this->insertCurrency(
            $this->supplierId,
            'EUR',
            null,
            null,
            'CZ1801000000001000000005',
            'Syntetická euro banka',
        );
        $this->insertCurrency(
            $this->supplierId,
            'CZK',
            '1',
            '0100',
            null,
            'Neplatná banka',
        );
        $this->insertCurrency(
            $this->otherSupplierId,
            'CZK',
            '1000000005',
            '0100',
            null,
            'Cizí banka',
        );

        $options = $this->queries->payerOptions($this->supplierId);

        self::assertCount(2, $options);
        self::assertSame(
            ['CZK' => ['abo'], 'EUR' => ['sepa']],
            array_column($options, 'export_formats', 'currency_code'),
        );
        self::assertSame('••••0005/0100', $options[0]['masked_account']);
        self::assertSame(
            'CZ•• •••• •••• •••• •••• 0005',
            $options[1]['masked_account'],
        );
        $encoded = json_encode($options, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('1000000005', $encoded);
        self::assertStringNotContainsString(
            'CZ1801000000001000000005',
            $encoded,
        );
        self::assertStringNotContainsString('Cizí banka', $encoded);
    }

    public function testListsBatchesAndExportsOnlyForTenantPeriod(): void
    {
        $batchId = $this->insertBatch($this->supplierId, '2026-08-15');
        $this->insertExport($this->supplierId, $batchId);
        $this->insertBatch($this->supplierId, '2026-09-01');
        $otherBatch = $this->insertBatch(
            $this->otherSupplierId,
            '2026-08-15',
        );
        $this->insertExport($this->otherSupplierId, $otherBatch);

        $batches = $this->queries->batchesForPeriod(
            $this->supplierId,
            '2026-08',
        );

        self::assertCount(1, $batches);
        self::assertSame($batchId, $batches[0]['id']);
        self::assertSame(123_456, $batches[0]['declared_total_minor']);
        self::assertSame(0, $batches[0]['settled_minor']);
        self::assertCount(1, $batches[0]['exports']);
        self::assertSame(
            'mzdy-platby-2026-08-15.kpc',
            $batches[0]['exports'][0]['suggested_filename'],
        );
        $encoded = json_encode($batches, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('snapshot_ciphertext', $encoded);
        self::assertStringNotContainsString('instruction_ciphertext', $encoded);
        self::assertStringNotContainsString('payer_reference', $encoded);
    }

    private function insertCurrency(
        int $supplierId,
        string $code,
        ?string $accountNumber,
        ?string $bankCode,
        ?string $iban,
        string $bankName,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en,
                 decimals, is_active, is_default, account_number,
                 bank_code, iban, bank_name)
             VALUES (?, ?, "Citlivý label 1000000005", ?, ?, ?, 2, 1, 0,
                     ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $code,
            $code === 'CZK' ? 'Kč' : '€',
            $code,
            $code,
            $accountNumber,
            $bankCode,
            $iban,
            $bankName,
        ]);
    }

    private function insertBatch(int $supplierId, string $date): int
    {
        $hash = hash(
            'sha256',
            "synthetic-query-batch:{$supplierId}:{$date}",
        );
        $reference = 'synthetic-query-' . $supplierId . '-'
            . str_replace('-', '', $date);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 direction, planned_payment_date, currency_code,
                 payer_reference, declared_total_minor,
                 declared_item_count, snapshot_ciphertext, snapshot_hash,
                 idempotency_key_hash)
             VALUES (?, ?, "bank", "abo", "outgoing", ?, "CZK",
                     "currency:1", 123456, 1, ?, ?, ?)',
        )->execute([
            $supplierId,
            $reference,
            $date,
            'enc:v2:synthetic-query',
            $hash,
            hash('sha256', $reference, true),
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertExport(int $supplierId, int $batchId): void
    {
        $batch = $this->db->pdo()->prepare(
            'SELECT snapshot_hash, planned_payment_date
               FROM payroll_payment_batches
              WHERE supplier_id = ? AND id = ?',
        );
        $batch->execute([$supplierId, $batchId]);
        $row = $batch->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $hash = hash('sha256', "synthetic-export:{$supplierId}:{$batchId}");
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_exports
                (supplier_id, batch_id, export_format,
                 export_revision_no, source_snapshot_hash,
                 exporter_version, file_sha256, size_bytes, mime_type,
                 storage_key, suggested_filename, manifest_json,
                 idempotency_key_hash)
             VALUES (?, ?, "abo", 1, ?, "synthetic.v1", ?, 123,
                     "text/plain", ?, ?, "{}", ?)',
        )->execute([
            $supplierId,
            $batchId,
            $row['snapshot_hash'],
            $hash,
            $hash,
            'mzdy-platby-' . $row['planned_payment_date'] . '.kpc',
            hash('sha256', "synthetic-export-idem:{$supplierId}:{$batchId}", true),
        ]);
    }
}
