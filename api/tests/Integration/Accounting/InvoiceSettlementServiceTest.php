<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\InvoiceSettlementService;
use MyInvoice\Service\Accounting\SettlementException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy úhrady faktury zápočtem proti zvolenému účtu (migrace 1126):
 * kontace 355/311 a 321/365, částečný zápočet vydané faktury, plná výše u přijaté,
 * storno, cizí měna a překročení zbytku. Vše v transakci → rollback.
 */
#[Group('integration')]
final class InvoiceSettlementServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2099;

    private Connection $db;
    private InvoiceSettlementService $service;
    private JournalEntryRepository $journal;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsRepository $accounts;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db       = $container->get(Connection::class);
            $this->service  = $container->get(InvoiceSettlementService::class);
            $this->journal  = $container->get(JournalEntryRepository::class);
            $this->periods  = $container->get(AccountingPeriodRepository::class);
            $this->accounts = $container->get(ChartOfAccountsRepository::class);
            $seeder         = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $seeder->seedForSupplier($this->supplierId);
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    public function testDefaultAccountsFromPostingRules(): void
    {
        self::assertSame('355', $this->service->defaultAccount($this->supplierId, 'invoice')['account_code']);
        self::assertSame('365', $this->service->defaultAccount($this->supplierId, 'purchase_invoice')['account_code']);
    }

    public function testSaleInvoiceSettlementPostsAgainstChosenAccount(): void
    {
        $client = $this->client('Odběratel', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-500', $client, 12100.00);

        $res = $this->service->create($this->supplierId, 'invoice', $invoiceId, [
            'settled_on' => self::YEAR . '-06-30',
            'amount'     => 12100.00,
            'account_id' => $this->accountId('355'),
            'note'       => 'Zápočet za společníkem',
        ], $this->userId);

        self::assertSame('confirmed', $res['status']);
        $byAcc = $this->linesByAccountCode((int) $res['journal_entry_id']);
        self::assertEqualsWithDelta(12100.00, $byAcc['355']['debit'], 0.001);
        self::assertEqualsWithDelta(12100.00, $byAcc['311']['credit'], 0.001);

        $inv = $this->invoiceRow($invoiceId);
        self::assertSame('paid', $inv['status']);
        self::assertEqualsWithDelta(12100.00, (float) $inv['paid_total'], 0.001);

        $payments = $this->paymentRows($invoiceId);
        self::assertCount(1, $payments);
        self::assertSame('settlement', $payments[0]['source']);
    }

    public function testPartialSettlementOfSaleInvoice(): void
    {
        $client = $this->client('Odběratel', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-501', $client, 10000.00);

        $this->service->create($this->supplierId, 'invoice', $invoiceId, [
            'settled_on' => self::YEAR . '-06-30', 'amount' => 4000.00, 'account_id' => $this->accountId('355'),
        ], $this->userId);

        $inv = $this->invoiceRow($invoiceId);
        self::assertEqualsWithDelta(4000.00, (float) $inv['paid_total'], 0.001);
        self::assertNotSame('paid', $inv['status'], 'Částečný zápočet fakturu neuzavře.');

        // Zbytek nad rámec zůstatku musí selhat.
        try {
            $this->service->create($this->supplierId, 'invoice', $invoiceId, [
                'settled_on' => self::YEAR . '-06-30', 'amount' => 6500.00, 'account_id' => $this->accountId('355'),
            ], $this->userId);
            self::fail('Zápočet nad zbytek k úhradě musí selhat.');
        } catch (SettlementException $e) {
            self::assertSame('amount_over_remaining', $e->errorCode);
        }

        // Doplacení přesně na zbytek uzavře fakturu.
        $this->service->create($this->supplierId, 'invoice', $invoiceId, [
            'settled_on' => self::YEAR . '-06-30', 'amount' => 6000.00, 'account_id' => $this->accountId('355'),
        ], $this->userId);
        self::assertSame('paid', $this->invoiceRow($invoiceId)['status']);
    }

    public function testPurchaseInvoiceSettlementRequiresFullAmount(): void
    {
        $vendor = $this->client('Dodavatel', false, true);
        $pfId = $this->purchaseInvoice('PF-2099-500', $vendor, 5000.00);

        try {
            $this->service->create($this->supplierId, 'purchase_invoice', $pfId, [
                'settled_on' => self::YEAR . '-06-30', 'amount' => 2000.00, 'account_id' => $this->accountId('365'),
            ], $this->userId);
            self::fail('Částečný zápočet PF musí selhat.');
        } catch (SettlementException $e) {
            self::assertSame('partial_not_supported', $e->errorCode);
        }

        $res = $this->service->create($this->supplierId, 'purchase_invoice', $pfId, [
            'settled_on' => self::YEAR . '-06-30', 'amount' => 5000.00, 'account_id' => $this->accountId('365'),
        ], $this->userId);

        $byAcc = $this->linesByAccountCode((int) $res['journal_entry_id']);
        self::assertEqualsWithDelta(5000.00, $byAcc['321']['debit'], 0.001);
        self::assertEqualsWithDelta(5000.00, $byAcc['365']['credit'], 0.001);
        self::assertSame('paid', $this->purchaseStatus($pfId));
    }

    public function testCancelReversesEntryAndUndoesPayment(): void
    {
        $client = $this->client('Odběratel', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-502', $client, 3000.00);
        $res = $this->service->create($this->supplierId, 'invoice', $invoiceId, [
            'settled_on' => self::YEAR . '-06-30', 'amount' => 3000.00, 'account_id' => $this->accountId('355'),
        ], $this->userId);

        $cancelled = $this->service->cancel($this->supplierId, (int) $res['id'], ['entry_date' => self::YEAR . '-06-30']);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertNotNull($cancelled['reversal_entry_id']);

        // Původní zápis zůstává + má protizápis (storno, ne smazání).
        self::assertNotNull($this->journal->find((int) $res['journal_entry_id'], $this->supplierId));
        self::assertCount(0, $this->paymentRows($invoiceId));
        $inv = $this->invoiceRow($invoiceId);
        self::assertEqualsWithDelta(0.0, (float) $inv['paid_total'], 0.001);
        self::assertNotSame('paid', $inv['status']);

        // Druhé zrušení už selže.
        try {
            $this->service->cancel($this->supplierId, (int) $res['id'], []);
            self::fail('Druhé zrušení musí selhat.');
        } catch (SettlementException $e) {
            self::assertSame('already_cancelled', $e->errorCode);
        }
    }

    public function testCounterAccountCannotBeDocumentAccount(): void
    {
        $client = $this->client('Odběratel', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-503', $client, 1000.00);

        try {
            $this->service->create($this->supplierId, 'invoice', $invoiceId, [
                'settled_on' => self::YEAR . '-06-30', 'amount' => 1000.00, 'account_id' => $this->accountId('311'),
            ], $this->userId);
            self::fail('Protiúčet 311 u vydané faktury musí selhat.');
        } catch (SettlementException $e) {
            self::assertSame('account_same_as_document', $e->errorCode);
        }
    }

    public function testForeignCurrencyRefused(): void
    {
        $eurId = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'EUR' LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId === 0) {
            self::markTestSkipped('EUR není v číselníku měn.');
        }
        $client = $this->client('Odběratel EU', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-504', $client, 500.00, $eurId);

        try {
            $this->service->create($this->supplierId, 'invoice', $invoiceId, [
                'settled_on' => self::YEAR . '-06-30', 'amount' => 500.00, 'account_id' => $this->accountId('355'),
            ], $this->userId);
            self::fail('Cizoměnový doklad musí selhat.');
        } catch (SettlementException $e) {
            self::assertSame('foreign_currency', $e->errorCode);
        }
    }

    // ── Helpery ───────────────────────────────────────────────────────────────

    private function accountId(string $code): int
    {
        $account = $this->accounts->findByCode($this->supplierId, $code);
        self::assertNotNull($account, 'Účet ' . $code . ' chybí v osnově.');
        return (int) $account['id'];
    }

    /** @return array<string, array{debit:float, credit:float}> */
    private function linesByAccountCode(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, l.amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ?'
        );
        $stmt->execute([$entryId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $code = (string) $row['account_code'];
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][(string) $row['side']] += (float) $row['amount'];
        }
        return $out;
    }

    private function client(string $name, bool $customer, bool $vendor): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId, $customer ? 1 : 0, $vendor ? 1 : 0]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function saleInvoice(string $varsymbol, int $clientId, float $total, ?int $currencyId = null): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, 0, ?, 0, "issued", "1", ?)'
        );
        $issue = self::YEAR . '-06-10';
        $stmt->execute([
            $this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue,
            $currencyId ?? $this->currencyId, $total, $total, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchaseInvoice(string $number, int $vendorId, float $total): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, "received", "40", "full", ?)'
        );
        $issue = self::YEAR . '-06-10';
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue,
            $this->currencyId, $total, $total, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function invoiceRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, paid_total, amount_to_pay FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function paymentRows(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, source, amount FROM invoice_payments WHERE invoice_id = ?');
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function purchaseStatus(int $id): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);
        return (string) $stmt->fetchColumn();
    }
}
