<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\GoPay;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\SaldoRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\GoPay\GoPayException;
use MyInvoice\Service\Accounting\GoPay\GoPayPendingService;
use MyInvoice\Service\Accounting\GoPay\GoPayService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * GoPay úhrada faktury se účtuje dnem platby (MD GoPay / D 311), import vyúčtování
 * čekající pohyb převezme bez druhého zápisu a smazání vyúčtování ho vrátí mezi
 * čekající. Bez toho zůstávala 311 v hlavní knize otevřená až do vyúčtování,
 * zatímco faktura i saldokonto byly uhrazené.
 */
#[Group('integration')]
final class GoPayPendingPaymentTest extends TestCase
{
    private const YEAR = 2097;
    private const SESSION = '1000000001';

    private Connection $db;
    private GoPayService $service;
    private GoPayPendingService $pending;
    private InvoicePaymentService $payments;
    private PostingService $posting;
    private JournalEntryRepository $journal;
    private ClosingRepository $closing;
    private SaldoRepository $saldo;
    private AccountingPeriodRepository $periods;
    private int $supplierId;
    private int $userId;
    private int $currencyId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 5);
        if (!is_file($root . '/cfg.php')) {
            $this->markTestSkipped('Test vyžaduje lokální databázi.');
        }
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->service = $container->get(GoPayService::class);
        $this->pending = $container->get(GoPayPendingService::class);
        $this->payments = $container->get(InvoicePaymentService::class);
        $this->posting = $container->get(PostingService::class);
        $this->journal = $container->get(JournalEntryRepository::class);
        $this->closing = $container->get(ClosingRepository::class);
        $this->saldo = $container->get(SaldoRepository::class);
        $this->periods = $container->get(AccountingPeriodRepository::class);
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query("SELECT id FROM supplier WHERE accounting_mode='double_entry' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí firma s podvojným účetnictvím nebo uživatel.');
        }
        $currency = $pdo->prepare("SELECT id FROM currencies WHERE supplier_id=? AND code='CZK' ORDER BY id LIMIT 1");
        $currency->execute([$this->supplierId]);
        $this->currencyId = (int) ($currency->fetchColumn() ?: 0);
        if ($this->currencyId === 0) {
            $this->markTestSkipped('Firma nemá CZK měnu.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $period = $this->periods->findForDate($this->supplierId, self::YEAR . '-01-15');
        if ($period === null) {
            $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        } elseif ($period['status'] !== 'open') {
            $pdo->prepare('UPDATE accounting_periods SET status="open" WHERE id=?')->execute([(int) $period['id']]);
        }
        $pdo->prepare('DELETE FROM gopay_settings WHERE supplier_id=?')->execute([$this->supplierId]);
        $pdo->prepare('UPDATE accounting_supplier_settings SET locked_until=NULL WHERE supplier_id=?')
            ->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testPaymentIsPostedOnPaymentDayAndReceivableClosesInLedger(): void
    {
        $this->configureAccounts();
        [$invoiceId] = $this->documents();
        $this->postDocuments($invoiceId, null);

        $this->payments->recordPayment($invoiceId, 1000, self::YEAR . '-01-15', [
            'bank_reference' => 'GOPAY:' . self::SESSION,
            'source' => 'manual',
            'created_by' => $this->userId,
        ]);

        $movement = $this->pendingMovement($invoiceId);
        self::assertNull($movement['clearing_id']);
        self::assertSame('payment', $movement['origin']);
        self::assertSame('posted', $movement['status']);
        self::assertSame(self::YEAR . '-01-15', $movement['performed_on']);
        $entry = $this->journal->find((int) $movement['journal_entry_id'], $this->supplierId);
        self::assertIsArray($entry);
        self::assertSame(self::YEAR . '-01-15', $entry['entry_date']);
        $this->assertPair((int) $movement['journal_entry_id'], '221.GP97', '311', 1000.00);

        $asOf = self::YEAR . '-01-31';
        self::assertSame(0.0, $this->receivableInLedger($invoiceId, $asOf));
        self::assertNotContains($invoiceId, array_column($this->closing->paidInvoicesOpenSaldo($this->supplierId, $asOf), 'id'));
        $receivable = $this->accountId('311');
        $open = array_filter(
            $this->saldo->openItems($this->supplierId, $receivable, $asOf, '311'),
            static fn (array $row): bool => $row['doc_type'] === 'invoice' && $row['doc_id'] === $invoiceId,
        );
        self::assertSame([], array_values($open));

        $overview = $this->pending->overview($this->supplierId);
        self::assertSame([['currency' => 'CZK', 'amount' => 1000.0, 'count' => 1]], $overview['totals']);
        self::assertSame(0, $overview['unrecorded_count']);
        self::assertSame(0, $overview['unposted_count']);
    }

    public function testClearingImportAdoptsPendingMovementAndDeleteReleasesIt(): void
    {
        $this->configureAccounts();
        [$invoiceId, $creditNoteId] = $this->documents();
        $this->postDocuments($invoiceId, $creditNoteId);
        $this->payments->recordPayment($invoiceId, 1000, self::YEAR . '-01-15', [
            'bank_reference' => 'GOPAY:' . self::SESSION,
            'source' => 'manual',
            'created_by' => $this->userId,
        ]);
        $pending = $this->pendingMovement($invoiceId);
        $this->bankPayout();

        $import = $this->service->import($this->supplierId, $this->userId, 'synthetic-pending.xml', $this->xml());
        $clearingId = (int) $import['clearing']['id'];
        self::assertSame('processed', $import['clearing']['status']);
        self::assertSame(0, $import['clearing']['issue_count']);
        self::assertSame(5, $import['clearing']['posted_count']);
        self::assertSame(5, $this->goPayEntryCount());

        $adopted = $this->pendingMovement($invoiceId);
        self::assertSame($pending['id'], $adopted['id']);
        self::assertSame($clearingId, (int) $adopted['clearing_id']);
        self::assertSame('TEST-MOVE-2097-1', $adopted['external_id']);
        self::assertSame('TEST000001', $adopted['order_id']);
        self::assertSame($pending['journal_entry_id'], $adopted['journal_entry_id']);
        self::assertSame([], $this->pending->overview($this->supplierId)['items']);

        $duplicate = $this->service->import($this->supplierId, $this->userId, 'synthetic-pending.xml', $this->xml());
        self::assertTrue($duplicate['duplicate']);
        $this->service->process($this->supplierId, $clearingId, $this->userId);
        self::assertSame(5, $this->goPayEntryCount());

        try {
            $this->payments->deletePayment((int) $pending['invoice_payment_id']);
            self::fail('Úhradu potvrzenou vyúčtováním nejde smazat.');
        } catch (GoPayException $e) {
            self::assertSame('payment_in_clearing', $e->errorCode);
        }

        $deleted = $this->service->delete($this->supplierId, $clearingId, $this->userId);
        self::assertNotContains((int) $pending['journal_entry_id'], $deleted['deleted_entry_ids']);
        $released = $this->pendingMovement($invoiceId);
        self::assertSame($pending['id'], $released['id']);
        self::assertNull($released['clearing_id']);
        self::assertNull($released['external_id']);
        self::assertNotNull($this->journal->find((int) $pending['journal_entry_id'], $this->supplierId));
        self::assertSame(1, $this->goPayEntryCount());
        self::assertSame(0.0, $this->receivableInLedger($invoiceId, self::YEAR . '-01-31'));

        $reimport = $this->service->import($this->supplierId, $this->userId, 'synthetic-pending.xml', $this->xml());
        self::assertFalse($reimport['duplicate']);
        self::assertSame('processed', $reimport['clearing']['status']);
        self::assertSame(5, $this->goPayEntryCount());
        self::assertSame((int) $reimport['clearing']['id'], (int) $this->pendingMovement($invoiceId)['clearing_id']);
    }

    public function testDeletingPaymentRemovesPendingMovementAndItsEntry(): void
    {
        $this->configureAccounts();
        [$invoiceId, $creditNoteId] = $this->documents();
        $this->postDocuments($invoiceId, $creditNoteId);
        $recorded = $this->payments->recordPayment($invoiceId, 1000, self::YEAR . '-01-15', [
            'bank_reference' => 'GOPAY:' . self::SESSION,
            'source' => 'manual',
            'created_by' => $this->userId,
        ]);
        $movement = $this->pendingMovement($invoiceId);

        $result = $this->payments->deletePayment($recorded['payment_id']);

        self::assertTrue($result['became_unpaid']);
        self::assertNull($this->journal->find((int) $movement['journal_entry_id'], $this->supplierId));
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM gopay_movements WHERE id=?');
        $count->execute([(int) $movement['id']]);
        self::assertSame(0, (int) $count->fetchColumn());
        self::assertSame(1000.0, $this->receivableInLedger($invoiceId, self::YEAR . '-01-31'));
    }

    public function testPaymentPostedDirectlyFromClearingCannotBeDeleted(): void
    {
        $this->configureAccounts();
        [$invoiceId, $creditNoteId] = $this->documents();
        $this->postDocuments($invoiceId, $creditNoteId);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id,invoice_id,paid_on,amount,currency,bank_reference,source,created_by)
             VALUES (?,?,?,1000,"CZK",?,"mark_paid",?)'
        )->execute([$this->supplierId, $invoiceId, self::YEAR . '-01-15', 'GOPAY:' . self::SESSION, $this->userId]);
        $paymentId = (int) $this->db->pdo()->lastInsertId();
        $this->bankPayout();
        $this->service->import($this->supplierId, $this->userId, 'synthetic-pending.xml', $this->xml());
        $movement = $this->pendingMovement($invoiceId);
        self::assertSame('clearing', $movement['origin']);
        self::assertSame($paymentId, (int) $movement['invoice_payment_id']);

        try {
            $this->payments->deletePayment($paymentId);
            self::fail('Úhradu zaúčtovanou z vyúčtování nejde smazat.');
        } catch (GoPayException $e) {
            self::assertSame('payment_in_clearing', $e->errorCode);
        }
        self::assertNotNull($this->payments->findPayment($paymentId));
        self::assertSame(0.0, $this->receivableInLedger($invoiceId, self::YEAR . '-01-31'));
    }

    public function testBackfillPostsLegacyPaymentsOnceAndIsIdempotent(): void
    {
        $this->configureAccounts();
        [$invoiceId, $creditNoteId] = $this->documents();
        $this->postDocuments($invoiceId, $creditNoteId);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id,invoice_id,paid_on,amount,currency,bank_reference,source,created_by)
             VALUES (?,?,?,1000,"CZK",?,"mark_paid",?)'
        )->execute([$this->supplierId, $invoiceId, self::YEAR . '-01-15', 'GOPAY:' . self::SESSION, $this->userId]);
        self::assertSame(1, $this->pending->overview($this->supplierId)['unrecorded_count']);
        self::assertSame(1000.0, $this->receivableInLedger($invoiceId, self::YEAR . '-01-31'));

        $first = $this->pending->postPending($this->supplierId, $this->userId);
        self::assertSame(1, $first['created']);
        self::assertSame(1, $first['posted']);
        self::assertSame([], $first['issues']);
        self::assertSame(0.0, $this->receivableInLedger($invoiceId, self::YEAR . '-01-31'));

        $second = $this->pending->postPending($this->supplierId, $this->userId);
        self::assertSame(0, $second['created']);
        self::assertSame(1, $second['posted']);
        self::assertSame(1, $this->goPayEntryCount());
        self::assertSame(0, $this->pending->overview($this->supplierId)['unrecorded_count']);
    }

    public function testLockedPaymentDateLeavesPendingMovementWithIssueUntilUnlocked(): void
    {
        $this->configureAccounts();
        [$invoiceId, $creditNoteId] = $this->documents();
        $this->postDocuments($invoiceId, $creditNoteId);
        $this->lockUntil(self::YEAR . '-01-20');

        $this->payments->recordPayment($invoiceId, 1000, self::YEAR . '-01-15', [
            'bank_reference' => 'GOPAY:' . self::SESSION,
            'source' => 'manual',
            'created_by' => $this->userId,
        ]);

        $movement = $this->pendingMovement($invoiceId);
        self::assertSame('error', $movement['status']);
        self::assertSame('date_locked', $movement['issue_code']);
        self::assertNull($movement['journal_entry_id']);
        self::assertSame(1, $this->pending->overview($this->supplierId)['unposted_count']);

        $this->lockUntil(null);
        $result = $this->pending->postPending($this->supplierId, $this->userId);
        self::assertSame(0, $result['created']);
        self::assertSame([], $result['issues']);
        self::assertSame(self::YEAR . '-01-15', $this->journal->find(
            (int) $this->pendingMovement($invoiceId)['journal_entry_id'],
            $this->supplierId,
        )['entry_date']);
    }

    public function testSupplierWithoutGoPaySettingsKeepsPaymentUnposted(): void
    {
        [$invoiceId, $creditNoteId] = $this->documents();
        $this->postDocuments($invoiceId, $creditNoteId);

        $this->payments->recordPayment($invoiceId, 1000, self::YEAR . '-01-15', [
            'bank_reference' => 'GOPAY:' . self::SESSION,
            'source' => 'manual',
            'created_by' => $this->userId,
        ]);

        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM gopay_movements WHERE supplier_id=? AND invoice_id=?');
        $count->execute([$this->supplierId, $invoiceId]);
        self::assertSame(0, (int) $count->fetchColumn());
        self::assertSame(1000.0, $this->receivableInLedger($invoiceId, self::YEAR . '-01-31'));
    }

    private function configureAccounts(): void
    {
        $pdo = $this->db->pdo();
        $parent = $this->accountId('221');
        $insert = $pdo->prepare(
            'INSERT INTO chart_of_accounts (supplier_id,account_code,name,account_type,normal_side,is_synthetic,parent_id)
             VALUES (?,?,?,"asset","debit",0,?)'
        );
        $insert->execute([$this->supplierId, '221.GP97', 'GoPay test', $parent]);
        $gopayId = (int) $pdo->lastInsertId();
        $insert->execute([$this->supplierId, '221.BK97', 'Banka test', $parent]);
        $bankId = (int) $pdo->lastInsertId();

        $this->service->saveSettings($this->supplierId, [
            'currency' => 'CZK',
            'gopay_account_id' => $gopayId,
            'receivable_account_id' => $this->accountId('311'),
            'fee_account_id' => $this->accountId('568'),
            'clearing_account_id' => $this->accountId('261'),
            'destination_bank_account_id' => $bankId,
            'payout_account_number' => '1000000005',
            'payout_bank_code' => '0100',
            'payout_date_tolerance_days' => 3,
        ], $this->userId);
    }

    private function accountId(string $code): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code=?');
        $stmt->execute([$this->supplierId, $code]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{int,int} */
    private function documents(): array
    {
        $pdo = $this->db->pdo();
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor)
             VALUES (?,"Test GoPay","Test 1","Praha","11000",?,"test@example.test","cs",?,1,0)'
        )->execute([$this->supplierId, $countryId, $this->currencyId]);
        $clientId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id,varsymbol,invoice_type,parent_invoice_id,client_id,issue_date,tax_date,due_date,
                 currency_id,reverse_charge,total_without_vat,total_vat,total_with_vat,paid_total,status,
                 supplier_order_number,note_below_items,vat_classification_code,created_by)
             VALUES (?,?,?,?,?,?,?,?,?,0,?,0,?,0,?,?,?,"1",?)'
        );
        $insert->execute([$this->supplierId, '20970001', 'invoice', null, $clientId, self::YEAR . '-01-10', self::YEAR . '-01-10', self::YEAR . '-01-20',
            $this->currencyId, 1000, 1000, 'sent', 'TEST000001', null, $this->userId]);
        $invoiceId = (int) $pdo->lastInsertId();
        $insert->execute([$this->supplierId, '20970002', 'credit_note', $invoiceId, $clientId, self::YEAR . '-01-20', self::YEAR . '-01-20', self::YEAR . '-01-20',
            $this->currencyId, -100, -100, 'sent', 'TEST000001', null, $this->userId]);
        return [$invoiceId, (int) $pdo->lastInsertId()];
    }

    private function postDocuments(int $invoiceId, ?int $creditNoteId): void
    {
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1000],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 1000],
        ], ['entry_date' => self::YEAR . '-01-10', 'document_no' => 'FV-TEST', 'description' => 'Test faktura', 'posted_by' => $this->userId]);
        if ($creditNoteId === null) {
            return;
        }
        $this->posting->postDocument($this->supplierId, 'invoice', $creditNoteId, [
            ['account_code' => '602', 'side' => 'debit', 'amount' => 100],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 100],
        ], ['entry_date' => self::YEAR . '-01-20', 'document_no' => 'DB-TEST', 'description' => 'Test dobropis', 'posted_by' => $this->userId]);
    }

    /** @return array<string,mixed> */
    private function pendingMovement(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM gopay_movements WHERE supplier_id=? AND invoice_id=? AND movement_type="credit" ORDER BY id'
        );
        $stmt->execute([$this->supplierId, $invoiceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        return $rows[0];
    }

    /** Zůstatek 311 v hlavní knize z předpisu faktury a jejích GoPay úhrad. */
    private function receivableInLedger(int $invoiceId, string $asOf): float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(CASE WHEN l.side="debit" THEN l.amount ELSE -l.amount END),0)
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id=e.id AND l.supplier_id=e.supplier_id
               JOIN chart_of_accounts ca ON ca.id=l.account_id
              WHERE e.supplier_id=? AND e.entry_date<=? AND ca.account_code LIKE "311%"
                AND ((e.source_type="invoice" AND e.source_id=?)
                     OR (e.source_type="gopay" AND e.source_id IN
                         (SELECT id FROM gopay_movements WHERE supplier_id=? AND invoice_id=?)))'
        );
        $stmt->execute([$this->supplierId, $asOf, $invoiceId, $this->supplierId, $invoiceId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private function goPayEntryCount(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM journal_entries e
               JOIN gopay_movements gm ON gm.id=e.source_id AND gm.supplier_id=e.supplier_id
              WHERE e.supplier_id=? AND e.source_type="gopay" AND e.entry_date BETWEEN ? AND ?'
        );
        $stmt->execute([$this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31']);
        return (int) $stmt->fetchColumn();
    }

    private function lockUntil(?string $date): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id,locked_until) VALUES (?,?)
             ON DUPLICATE KEY UPDATE locked_until=VALUES(locked_until)'
        )->execute([$this->supplierId, $date]);
    }

    private function bankPayout(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id,source,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by)
             VALUES (?,"gpc",?,?,"1000000005","0100","CZK",?,?)'
        )->execute([
            $this->supplierId,
            'synthetic-' . uniqid('', true) . '.gpc',
            hash('sha256', uniqid('gopay', true)),
            self::YEAR . '-02-01',
            $this->userId,
        ]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id,source,posted_at,amount,currency,variable_symbol,counterparty_account,
                 counterparty_bank,counterparty_name,description,match_status)
             VALUES (?,"statement",?,875,"CZK","20970001","1000000005","0100","GoPay","Clearing","unmatched")'
        )->execute([$statementId, self::YEAR . '-02-01']);
    }

    private function assertPair(int $entryId, string $debit, string $credit, float $amount): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT coa.account_code,jel.side,jel.amount FROM journal_entry_lines jel
             JOIN chart_of_accounts coa ON coa.id=jel.account_id WHERE jel.entry_id=? AND jel.supplier_id=?'
        );
        $stmt->execute([$entryId, $this->supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        self::assertContains(['account_code' => $debit, 'side' => 'debit', 'amount' => number_format($amount, 2, '.', '')], $rows);
        self::assertContains(['account_code' => $credit, 'side' => 'credit', 'amount' => number_format($amount, 2, '.', '')], $rows);
    }

    private function xml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<clearing xmlns="https://www.gopay.cz/clearing" accountName="Test CZK" amount="1000.00"
 amountCreditNote="0.00" amountFee="20.00" amountFeeExternal="10.00" amountSent="875.00"
 amountStorno="100.00" amountStornoFee="5.00" amountTransfer="875.00"
 clearingId="TEST-CLEARING-2097" dateClearedFrom="01.01.2097" dateClearedTo="31.01.2097"
 datePerformed="01.02.2097" variableSymbol="20970001">
 <paymentChannel fee="10.00" transactionFee="10.00" type="test" volumeFee="0.00"><movements>
  <movement accountMovementId="TEST-MOVE-2097-1" amount="1000.00" counterpartyName="test"
   datePerformed="15.01.2097" orderId="TEST000001" paymentSessionId="1000000001" type="credit"/>
 </movements></paymentChannel>
 <storno>
  <stornoMovement accountMovementId="TEST-MOVE-2097-2" amount="-100.00" counterpartyName="GOPAY"
   datePerformed="20.01.2097" orderId="TEST000001" paymentSessionId="1000000001" type="storno"/>
  <stornoMovement accountMovementId="TEST-MOVE-2097-3" amount="-5.00" counterpartyName="GOPAY"
   datePerformed="20.01.2097" orderId="TEST000001" paymentSessionId="1000000001" type="stornoFee"/>
 </storno>
</clearing>
XML;
    }
}
