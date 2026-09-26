<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingPayments;
use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Service\Migration\StereoNx\StereoNxImporter;
use MyInvoice\Service\Migration\StereoNx\StereoNxSourcePlan;
use MyInvoice\Service\Migration\Shared\MigratedPaymentWriter;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxTables;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticNx1Archive.php';
require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticStereoNxTables.php';

#[Group('integration')]
final class StereoNxAccountingPaymentsWriteTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxImporter $importer;
    private StereoNxAccountingPayments $payments;
    private int $supplierId;
    private int $userId;
    private string $archive;
    private \Psr\Container\ContainerInterface $container;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->container = $container;
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(StereoNxImporter::class);
        $this->payments = $container->get(StereoNxAccountingPayments::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn());
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $identity = SyntheticStereoNxTables::identity();
        $pdo->prepare("UPDATE supplier SET company_name=?, ic=?, dic=?, accounting_mode='double_entry' WHERE id=?")
            ->execute([$identity['name'], $identity['ico'], $identity['dic'], $this->supplierId]);
        $pdo->prepare('INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default)
            VALUES (?,"CZK","CZK","Kč","CZK","CZK",2,1,1)')->execute([$this->supplierId]);
        $pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([(int) $pdo->lastInsertId(), $this->supplierId]);
        $this->archive = sys_get_temp_dir() . '/stereo-payments-write-' . bin2hex(random_bytes(6)) . '.zip';
        SyntheticNx1Archive::write($this->archive, self::tables(), $identity);
    }

    protected function tearDown(): void
    {
        @unlink($this->archive ?? '');
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testDryRollbackActualAndRepeatKeepJournalEmpty(): void
    {
        $tables = self::tables();
        $documents = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);
        $documents['source_company_index'] = 0;
        $this->importer->writeAccountingPartners($documents['clients'], $documents['identity'], 0, $this->supplierId);
        $this->importer->writeAccountingDocuments($documents, $this->supplierId, $this->userId);
        $plan = $this->payments->prepare(StereoNxBackup::open($this->archive, 0));
        $plan['documents'] = $documents;

        $this->db->pdo()->exec('SAVEPOINT stereo_payments_dry');
        $dry = $this->payments->write($plan, $this->supplierId, $this->userId);
        self::assertSame(5, $dry['counts']['bank_transactions']);
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT stereo_payments_dry');
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM bank_statements WHERE supplier_id=?'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM cash_documents WHERE supplier_id=?'));

        $first = $this->payments->write($plan, $this->supplierId, $this->userId);
        self::assertSame(['bank_accounts' => 1, 'bank_statements' => 1, 'bank_transactions' => 5,
            'cash_transactions' => 1, 'payments' => 3], $first['counts']);
        self::assertSame(5, $this->scalar('SELECT COUNT(*) FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id WHERE bs.supplier_id=?'));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM cash_documents WHERE supplier_id=? AND status='draft' AND journal_entry_id IS NULL"));
        self::assertSame(3, $this->scalar('SELECT COUNT(*) FROM payment_matches WHERE supplier_id=?'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM invoice_payments WHERE supplier_id=?'));
        self::assertSame(3, $this->scalar("SELECT COUNT(*) FROM payment_matches pm
            JOIN bank_transactions bt ON bt.id=pm.bank_transaction_id
            WHERE pm.supplier_id=? AND bt.match_status='manual' AND bt.match_reason='migration_review'"));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=?'));
        self::assertGreaterThan(0, $first['review_movements'][0]['target_id']);

        $repeat = $this->payments->write($plan, $this->supplierId, $this->userId);
        self::assertSame(['bank_accounts' => 0, 'bank_statements' => 0, 'bank_transactions' => 0,
            'cash_transactions' => 0, 'payments' => 0], $repeat['counts']);
        self::assertGreaterThan(0, $repeat['review_movements'][0]['target_id']);

        $plan['records']['bank_transactions'][0]['description'] = 'Změněný zdroj';
        $this->expectException(\MyInvoice\Service\Migration\StereoNx\StereoNxException::class);
        $this->expectExceptionMessage('změnil');
        $this->payments->write($plan, $this->supplierId, $this->userId);
    }

    public function testReleasedMovementKeepsReviewFlagThroughRematchAndRules(): void
    {
        $documents = StereoNxSourcePlan::fromTables(self::tables(), SyntheticStereoNxTables::identity(), true, true);
        $documents['source_company_index'] = 0;
        $this->importer->writeAccountingPartners($documents['clients'], $documents['identity'], 0, $this->supplierId);
        $this->importer->writeAccountingDocuments($documents, $this->supplierId, $this->userId);
        $plan = $this->payments->prepare(StereoNxBackup::open($this->archive, 0));
        $plan['documents'] = $documents;
        $this->payments->write($plan, $this->supplierId, $this->userId);
        $txId = $this->scalar("SELECT bt.id FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id
            WHERE bs.supplier_id=? AND bt.match_status='manual' AND bt.match_reason='migration_review' ORDER BY bt.id LIMIT 1");
        self::assertGreaterThan(0, $txId);

        $container = $this->container;
        $container->get(\MyInvoice\Service\Bank\BankTransactionReleaseService::class)
            ->release($this->supplierId, $txId, \MyInvoice\Service\Bank\BankTransactionReleaseService::MODE_UNMATCH, $this->userId);
        $container->get(\MyInvoice\Service\Bank\StatementMatcher::class)->match($txId);
        self::assertSame('migration_review', $this->db->pdo()->query('SELECT match_reason FROM bank_transactions WHERE id=' . $txId)->fetchColumn());

        $posting = $container->get(\MyInvoice\Service\Accounting\Bank\BankPostingService::class);
        self::assertSame('migration_review', $posting->applyRules($this->supplierId, $txId, $this->userId)['reason'] ?? null);
        self::assertSame('migration_review', $posting->handleTransaction($txId, $this->userId)['reason'] ?? null);
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=? AND source_type='bank'"));
    }

    public function testPaymentRejectsChangedDocumentMapHash(): void
    {
        $documents = StereoNxSourcePlan::fromTables(self::tables(), SyntheticStereoNxTables::identity(), true, true);
        $documents['source_company_index'] = 0;
        $this->importer->writeAccountingPartners($documents['clients'], $documents['identity'], 0, $this->supplierId);
        $this->importer->writeAccountingDocuments($documents, $this->supplierId, $this->userId);
        $this->db->pdo()->prepare("UPDATE stereo_nx_import_map SET source_hash=? WHERE supplier_id=? AND kind='issued'")
            ->execute([str_repeat('0', 64), $this->supplierId]);
        $plan = $this->payments->prepare(StereoNxBackup::open($this->archive, 0));
        $plan['documents'] = $documents;
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM stereo_nx_import_map WHERE supplier_id=? AND kind='issued' AND source_hash='" . str_repeat('0', 64) . "'"));
        self::assertNotEmpty($plan['records']['payments']);
        $this->expectException(\MyInvoice\Service\Migration\StereoNx\StereoNxException::class);
        $this->expectExceptionMessage('změnil');
        $this->payments->write($plan, $this->supplierId, $this->userId);
    }

    public function testPaymentRejectsDocumentOwnedByAnotherSupplier(): void
    {
        $documents = StereoNxSourcePlan::fromTables(self::tables(), SyntheticStereoNxTables::identity(), true, true);
        $documents['source_company_index'] = 0;
        $this->importer->writeAccountingPartners($documents['clients'], $documents['identity'], 0, $this->supplierId);
        $this->importer->writeAccountingDocuments($documents, $this->supplierId, $this->userId);
        $other = $this->createIsolatedSupplier($this->db->pdo(), $this->supplierId);
        $this->db->pdo()->prepare('UPDATE invoices SET supplier_id=? WHERE supplier_id=?')->execute([$other, $this->supplierId]);
        $plan = $this->payments->prepare(StereoNxBackup::open($this->archive, 0));
        $plan['documents'] = $documents;
        self::assertNotEmpty($plan['records']['payments']);
        try {
            $this->payments->write($plan, $this->supplierId, $this->userId);
            self::fail('A payment must never target a document belonging to a different supplier.');
        } catch (\MyInvoice\Service\Migration\StereoNx\StereoNxException $e) {
            self::assertSame('mapped_target_missing', $e->errorCode);
        }
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payment_matches WHERE supplier_id=?'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM invoice_payments WHERE supplier_id=?'));
    }

    public function testSharedPaymentWriterRejectsForeignDocumentBeforeWritingCzkPayment(): void
    {
        $documents = StereoNxSourcePlan::fromTables(self::tables(), SyntheticStereoNxTables::identity(), true, true);
        $documents['source_company_index'] = 0;
        $this->importer->writeAccountingPartners($documents['clients'], $documents['identity'], 0, $this->supplierId);
        $this->importer->writeAccountingDocuments($documents, $this->supplierId, $this->userId);
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default)
            VALUES (?,"EUR","EUR","€","EUR","EUR",2,1,0)')->execute([$this->supplierId]);
        $eurId = (int) $pdo->lastInsertId();
        $invoiceId = (int) $pdo->query('SELECT id FROM invoices WHERE supplier_id=' . $this->supplierId . ' LIMIT 1')->fetchColumn();
        $pdo->prepare('UPDATE invoices SET currency_id=?, exchange_rate=25.1 WHERE id=? AND supplier_id=?')
            ->execute([$eurId, $invoiceId, $this->supplierId]);

        $writer = new MigratedPaymentWriter($this->db);
        try {
            $writer->attach($this->supplierId, $this->userId,
                'issued', 'bank', $invoiceId, 1, 100.0);
            self::fail('Korunová úhrada nesmí být uložena jako částka cizoměnového dokladu.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('payment_currency_unverified', $e->getMessage());
        }
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM invoice_payments WHERE supplier_id=?'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payment_matches WHERE supplier_id=?'));
        try {
            $writer->refreshBalances($this->supplierId, [$invoiceId], [], []);
            self::fail('Korunové součty nesmí přepsat cizoměnový zůstatek dokladu.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('payment_currency_unverified', $e->getMessage());
        }
    }

    /** @return array<string,list<array<string,mixed>>> */
    private static function tables(): array
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['LFirmaUc'][0]['BaUcet'] = '19-1000000005'; $tables['LFirmaUc'][0]['KodBanky'] = '0100';
        $tables['CBanka'][0]['Kurz'] = 1.0; $tables['CBanka'][0]['KurzMn'] = 1.0;
        foreach ($tables['CBankap'] as &$row) {
            $row['Kurz'] = 1.0; $row['KurzMn'] = 1.0; $row['CastkaVlastni'] = $row['Castka'];
            $row['DPHz'] = 0.0; $row['DPHs'] = 0.0; $row['DPHt'] = 0.0;
        }
        unset($row);
        foreach ($tables['CPokl'] as &$row) {
            $row['Kurz'] = 1.0; $row['KurzMn'] = 1.0; $row['CastkaVlastni'] = $row['Castka'];
            $row['DPHz'] = 0.0; $row['DPHs'] = 0.0; $row['DPHt'] = 0.0;
        }
        unset($row);
        foreach ($tables['CPZZ'] as &$row) $row['Mena'] = 'Kč';
        unset($row);
        foreach ($tables['Cpz'] as &$row) $row['Agenda'] = $row['DoklSRada'];
        unset($row);
        return $tables;
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->db->pdo()->prepare($sql); $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }
}
