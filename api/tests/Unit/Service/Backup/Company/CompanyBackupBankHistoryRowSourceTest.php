<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupBankHistoryRowSource;
use MyInvoice\Service\Backup\Company\CompanyBackupDataRowSource;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupBankHistoryRowSourceTest extends TestCase
{
    private const BACKUP = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';

    public function testSnapshotKeepsExistingReferencesAndDetachesOnlyMissingDocuments(): void
    {
        $pdo = $this->database();
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:bank_match_audit');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $projection->assertRegistryTargets(TenantDataRegistryFactory::draftV1());
        $row = array_replace(array_fill_keys($projection->dataColumns, null), [
            'id' => 31, 'supplier_id' => 7, 'bank_transaction_id' => 41,
            'decision' => 'suggest', 'invoice_ids' => '[11,99]', 'score' => '0.900',
        ]);
        $source = new CompanyBackupBankHistoryRowSource($this->source($row), self::BACKUP);
        $result = iterator_to_array($source->rows($pdo, 7, $definition))[0];
        $projection->assertCompleteSourceRow($result);
        self::assertSame('[11,null]', $result['invoice_ids']);
        $mapped = $projection->remapEmbeddedReferences($result, static fn (): int => 111);
        self::assertSame('[111,null]', $mapped['invoice_ids']);
        self::assertSame($result['archived_document_references'], $mapped['archived_document_references']);
        self::assertSame('[11,99]', $row['invoice_ids']);
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
    }

    public function testForeignTenantReferenceCannotBecomeAnArchivedMissingReference(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:bank_match_audit');
        self::assertNotNull($definition);
        $source = new CompanyBackupBankHistoryRowSource($this->source([
            'supplier_id' => 7, 'invoice_ids' => '[12]', 'purchase_invoice_id' => null,
        ]), self::BACKUP);
        $this->expectException(CompanyBackupDataSourceException::class);
        iterator_to_array($source->rows($this->database(), 7, $definition));
    }

    public function testProjectionRejectsReactivatedArchivedSuggestion(): void
    {
        $row = \MyInvoice\Service\Bank\Match\BankMatchArchivedDocuments::detachMissing(
            'bank_match_suggestions', ['supplier_id' => 7, 'status' => 'pending',
                'candidates_json' => '[{"type":"invoice","invoice_id":99}]'],
            self::BACKUP, static fn (): ?int => null);
        $row['status'] = 'pending';
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:bank_match_suggestions');
        self::assertNotNull($definition);
        $this->expectException(CompanyBackupDataSourceException::class);
        $this->expectExceptionMessage('data_bank_history_invalid');
        CompanyBackupTableProjection::fromDefinition($definition)->assertCompleteSourceRow($row);
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE purchase_invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL)');
        $pdo->exec('INSERT INTO invoices VALUES (11, 7), (12, 8)');
        return $pdo;
    }

    public function testArchivesMissingRuleTransactionButRejectsForeignAndUnownedTransactions(): void
    {
        $pdo = $this->database();
        $pdo->exec('CREATE TABLE bank_statements (id INTEGER, supplier_id INTEGER)');
        $pdo->exec('CREATE TABLE bank_transactions (id INTEGER, statement_id INTEGER)');
        $pdo->exec('INSERT INTO bank_statements VALUES (1, 7), (2, 8), (3, NULL)');
        $pdo->exec('INSERT INTO bank_transactions VALUES (11, 1), (12, 2), (13, 3)');
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:bank_posting_rules');
        self::assertNotNull($definition);
        foreach ([99, 11, 12, 13] as $id) {
            $row = ['supplier_id' => 7, 'last_rejected_tx_id' => $id, 'rejected_streak' => 2];
            $source = new CompanyBackupBankHistoryRowSource($this->source($row), self::BACKUP);
            try {
                $copy = iterator_to_array($source->rows($pdo, 7, $definition))[0];
                self::assertNotContains($id, [12, 13]);
                self::assertSame($id === 99 ? null : $id, $copy['last_rejected_tx_id']);
                self::assertSame(2, $copy['rejected_streak']);
                self::assertSame($id, $row['last_rejected_tx_id']);
            } catch (CompanyBackupDataSourceException $e) {
                self::assertContains($id, [12, 13]);
                self::assertSame('data_bank_history_invalid', $e->errorCode);
            }
        }
    }

    public function testProjectionRejectsMalformedRuleArchive(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:bank_posting_rules');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $row = array_replace(array_fill_keys($projection->dataColumns, null), [
            'id' => 51, 'supplier_id' => 7, 'debit_account_code' => '568',
            'credit_account_code' => '221', 'archived_rejected_transactions' => '{}',
        ]);
        $this->expectException(CompanyBackupDataSourceException::class);
        $this->expectExceptionMessage('data_bank_history_invalid');
        $projection->assertCompleteSourceRow($row);
    }

    /** @param array<string,mixed> $row */
    private function source(array $row): CompanyBackupDataRowSource
    {
        return new class($row) implements CompanyBackupDataRowSource {
            /** @param array<string,mixed> $row */
            public function __construct(private array $row) {}
            public function rows(PDO $snapshot, int $supplierId, TenantDataDefinition $definition): iterable
            {
                yield $this->row;
            }
        };
    }
}
