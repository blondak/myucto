<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankEmailNoticeRepository;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;
use MyInvoice\Service\Bank\StatementMatcher;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class BankEmailNoticeRepositoryTenantTest extends TestCase
{
    private const ACCOUNT = '9990562299';

    private Connection $db;
    private BankEmailNoticeRepository $repository;
    private StatementMatcher $matcher;
    private int $supplierId = 0;
    private int $otherSupplierId = 0;
    /** @var list<int> */
    private array $createdProcessedMessages = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->repository = $container->get(BankEmailNoticeRepository::class);
            $this->matcher = $container->get(StatementMatcher::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $this->supplierId = (int) ($this->db->pdo()->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }
        $this->cleanupStatements();
        $this->otherSupplierId = $this->cloneSupplier();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->createdProcessedMessages as $messageId) {
            $this->db->pdo()->prepare('DELETE FROM bank_email_processed_messages WHERE id = ?')
                ->execute([$messageId]);
        }
        $this->cleanupStatements();
        if ($this->otherSupplierId > 0) {
            $this->db->pdo()->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->otherSupplierId]);
        }
        $this->db->close();
    }

    public function testMonthlyStatementAndNoticeDedupeAreTenantScoped(): void
    {
        $notice = new ParsedBankEmailNotice(
            variableSymbol: '2099071401',
            amount: 123.45,
            currency: 'CZK',
            postedAt: '2099-07-14',
            recipientAccount: self::ACCOUNT . '/0100',
            counterpartyAccount: '1000000005',
            counterpartyBank: '0100',
            counterpartyName: 'Syntetická protistrana',
            message: 'Tenant scope test',
        );
        $sourceRef = 'imap-1:<shared-message@example.test>';

        $first = $this->repository->createTransactionFromNotice(
            $this->supplierId,
            $notice,
            $sourceRef,
            0.05,
            $this->matcher,
            'CZK',
        );
        $second = $this->repository->createTransactionFromNotice(
            $this->otherSupplierId,
            $notice,
            $sourceRef,
            0.05,
            $this->matcher,
            'CZK',
        );

        self::assertNotSame($first['statement_id'], $second['statement_id']);
        self::assertNotSame($first['transaction_id'], $second['transaction_id']);

        $statements = $this->db->pdo()->query(
            "SELECT supplier_id, file_hash, source_ref
               FROM bank_statements
              WHERE source = 'email_notice' AND account_number = '" . self::ACCOUNT . "'
              ORDER BY supplier_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $statements);
        self::assertSame([$this->supplierId, $this->otherSupplierId], array_map(
            static fn (array $row): int => (int) $row['supplier_id'],
            $statements,
        ));
        self::assertNotSame($statements[0]['file_hash'], $statements[1]['file_hash']);
        self::assertStringContainsString((string) $this->supplierId, (string) $statements[0]['source_ref']);
        self::assertStringContainsString((string) $this->otherSupplierId, (string) $statements[1]['source_ref']);

        $transactions = $this->db->pdo()->query(
            "SELECT bs.supplier_id, bt.source_ref
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.source = 'email_notice' AND bs.account_number = '" . self::ACCOUNT . "'
              ORDER BY bs.supplier_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $transactions);
        self::assertStringStartsWith('supplier-' . $this->supplierId . ':', (string) $transactions[0]['source_ref']);
        self::assertStringStartsWith('supplier-' . $this->otherSupplierId . ':', (string) $transactions[1]['source_ref']);
        self::assertNotSame($transactions[0]['source_ref'], $transactions[1]['source_ref']);

        $duplicate = $this->repository->createTransactionFromNotice(
            $this->supplierId,
            $notice,
            $sourceRef,
            0.05,
            $this->matcher,
            'CZK',
        );
        self::assertSame($first['statement_id'], $duplicate['statement_id']);
        self::assertSame($first['transaction_id'], $duplicate['transaction_id']);
        $reconnected = $this->repository->createTransactionFromNotice(
            $this->supplierId, $notice, 'imap-999:<shared-message@example.test>',
            0.05, $this->matcher, 'CZK',
        );
        self::assertSame($first['transaction_id'], $reconnected['transaction_id'],
            'Nové ID IMAP konfigurace nesmí vytvořit druhou úhradu stejné zprávy.');
    }

    public function testRestoredIdentitySurvivesNewTenantAndImapIdsIncludingMonthlyStatement(): void
    {
        $notice = new ParsedBankEmailNotice(
            variableSymbol: '2099071499', amount: 12.34, currency: 'CZK',
            postedAt: '2099-07-14', recipientAccount: self::ACCOUNT . '/0100',
            counterpartyAccount: '1000000005', counterpartyBank: '0100',
            counterpartyName: 'Synthetic restore', message: 'Restore identity test',
        );
        $first = $this->repository->createTransactionFromNotice(
            $this->supplierId, $notice, 'imap-7:<restore@example.test>', 0.05, $this->matcher, 'CZK',
        );
        $pdo = $this->db->pdo();
        // Jako při obnově: nová lokální ID, původní auditní reference a stabilní identita.
        $pdo->prepare('INSERT INTO bank_statements
            (supplier_id, source, source_ref, external_identity, file_name, file_hash,
             account_number, bank_code, currency, statement_date, transaction_count)
            SELECT ?, source, source_ref, external_identity, file_name, file_hash,
                   account_number, bank_code, currency, statement_date, transaction_count
              FROM bank_statements WHERE id = ?')->execute([$this->otherSupplierId, $first['statement_id']]);
        $restoredStatement = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO bank_transactions
            (statement_id, source, source_ref, external_identity, posted_at, amount, currency, variable_symbol)
            SELECT ?, source, source_ref, external_identity, posted_at, amount, currency, variable_symbol
              FROM bank_transactions WHERE id = ?')->execute([$restoredStatement, $first['transaction_id']]);
        $restoredTransaction = (int) $pdo->lastInsertId();
        $reconnected = $this->repository->createTransactionFromNotice(
            $this->otherSupplierId, $notice, 'imap-901:<restore@example.test>', 0.05, $this->matcher, 'CZK',
        );
        self::assertSame($restoredTransaction, $reconnected['transaction_id']);
        self::assertSame($restoredStatement, $reconnected['statement_id']);
        $newMessage = $this->repository->createTransactionFromNotice(
            $this->otherSupplierId, $notice, 'imap-901:<new@example.test>', 0.05, $this->matcher, 'CZK',
        );
        self::assertNotSame($restoredTransaction, $newMessage['transaction_id']);
        self::assertSame($restoredStatement, $newMessage['statement_id']);
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM bank_transactions WHERE statement_id = ' . $restoredStatement)->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM bank_transactions WHERE statement_id = ' . $first['statement_id'])->fetchColumn());
    }

    public function testIdokladRestoredMovementAndMonthIgnoreOldSupplierPrefix(): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO bank_statements
                (supplier_id, source, source_ref, external_identity, file_name, file_hash,
                 account_number, bank_code, currency, statement_date)
                VALUES (?, "idoklad", ?, "idoklad-month:99:2099-07", "synthetic", ?,
                        "1000000005", "0100", "CZK", "2099-07-14")')
                ->execute([$this->otherSupplierId, $this->supplierId . ':99:2099-07',
                    hash('sha256', 'synthetic-restored-month-' . $this->otherSupplierId)]);
            $statementId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO bank_transactions
                (source, source_ref, external_identity, statement_id, posted_at, amount)
                VALUES ("idoklad", ?, "idoklad:123", ?, "2099-07-14", 12.34)')
                ->execute([$this->supplierId . ':123', $statementId]);
            $class = new \ReflectionClass(\MyInvoice\Service\Import\IdokladBankTransactionImporter::class);
            $importer = $class->newInstanceWithoutConstructor();
            $exists = $class->getMethod('exists');
            self::assertTrue($exists->invoke($importer, $pdo, $this->otherSupplierId,
                $this->otherSupplierId . ':123', 'idoklad:123'));
            self::assertFalse($exists->invoke($importer, $pdo, $this->supplierId,
                $this->supplierId . ':123', 'idoklad:123'));
            self::assertFalse($exists->invoke($importer, $pdo, $this->otherSupplierId,
                $this->otherSupplierId . ':124', 'idoklad:124'));
            $month = $class->getMethod('statement');
            self::assertSame($statementId, $month->invoke($importer, $pdo,
                $this->otherSupplierId, 99, '2099-07-15',
                ['account_number' => '1000000005', 'bank_code' => '0100', 'currency' => 'CZK']));
            self::assertSame('2099-07-15', $pdo->query('SELECT statement_date FROM bank_statements WHERE id = ' . $statementId)->fetchColumn());
        } finally {
            $pdo->rollBack();
        }
    }

    public function testMatchedTransactionKeepsPostprocessErrorOnlyAsWarning(): void
    {
        $notice = new ParsedBankEmailNotice(
            variableSymbol: '2099071402',
            amount: 456.78,
            currency: 'CZK',
            postedAt: '2099-07-14',
            recipientAccount: self::ACCOUNT . '/0100',
            counterpartyAccount: '1000000005',
            counterpartyBank: '0100',
            counterpartyName: 'Syntetická protistrana',
            message: 'Postprocess status test',
        );
        $created = $this->repository->createTransactionFromNotice(
            $this->supplierId,
            $notice,
            'imap-1:<postprocess-status@example.test>',
            0.05,
            $this->matcher,
            'CZK',
        );
        $this->db->pdo()->prepare('UPDATE bank_transactions SET match_status = ? WHERE id = ?')
            ->execute(['manual', $created['transaction_id']]);

        $messageId = $this->repository->recordMessage([
            'supplier_id' => $this->supplierId,
            'imap_account_id' => null,
            'fallback_hash' => hash('sha256', 'postprocess-status-' . random_bytes(8)),
            'status' => 'postprocess_failed',
            'bank_statement_id' => $created['statement_id'],
            'bank_transaction_id' => $created['transaction_id'],
            'error_message' => 'no headers found',
        ]);
        $this->createdProcessedMessages[] = $messageId;

        $rows = $this->repository->processedMessages($this->supplierId, 500);
        $row = array_values(array_filter(
            $rows,
            static fn (array $item): bool => (int) $item['id'] === $messageId,
        ))[0] ?? null;

        self::assertNotNull($row);
        self::assertSame('postprocess_failed', $row['status']);
        self::assertSame('processed_success', $row['effective_status']);
        self::assertTrue($row['matched']);
        self::assertSame('no headers found', $row['error_message']);
    }

    private function cloneSupplier(): int
    {
        $clone = $this->db->pdo()->prepare(
            "INSERT INTO supplier
                (company_name,display_name,street,city,zip,country_id,is_vat_payer,email,
                 default_currency_id,default_vat_rate_id,default_payment_due_days,default_hourly_rate,accounting_mode)
             SELECT '__TEST EMAIL NOTICE TENANT B','__TEST EMAIL NOTICE TENANT B',street,city,zip,country_id,0,email,
                    default_currency_id,default_vat_rate_id,default_payment_due_days,default_hourly_rate,accounting_mode
               FROM supplier WHERE id=?"
        );
        $clone->execute([$this->supplierId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        self::assertGreaterThan(0, $id);
        return $id;
    }

    private function cleanupStatements(): void
    {
        $this->db->pdo()->prepare(
            "DELETE FROM bank_statements WHERE source = 'email_notice' AND account_number = ?"
        )->execute([self::ACCOUNT]);
    }
}
