<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Cash;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\Cash\CashSettlementService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hotovostní vyrovnání faktury z editoru dokladu (migrace 1327).
 *
 * Co se tu hlídá: vyrovnání je NEPOVINNÉ (bez pokladny se nic nezaúčtuje), VRATNÉ
 * (odebraná volba pokladní doklad smaže i s deníkem) a IDEMPOTENTNÍ (opakované
 * uložení doklad neduplikuje). Peněžní noha jde na ANALYTIKU pokladny, ne na plochou
 * 211. Uzavřené období se nesmí prorazit.
 */
#[Group('integration')]
final class CashSettlementServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2099;

    private Connection $db;
    private CashSettlementService $settlement;
    private CashRegisterService $registers;
    private JournalEntryRepository $journal;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db         = $container->get(Connection::class);
            $this->settlement = $container->get(CashSettlementService::class);
            $this->registers  = $container->get(CashRegisterService::class);
            $this->journal    = $container->get(JournalEntryRepository::class);
            $this->periods    = $container->get(AccountingPeriodRepository::class);
            $seeder           = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 21 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
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

    // ── přijatá faktura (VPD) ───────────────────────────────────────────────────

    public function testPurchaseSettlesOnAnalyticAccountAndIsIdempotent(): void
    {
        $register = $this->makeRegister('211.100');
        $pfId = $this->purchaseInvoice('FP-1', $this->client('Dodavatel a.s.', false, true), 3000.00);
        $this->chooseRegister('purchase_invoices', $pfId, $register);

        $first = $this->settlement->syncPurchase($this->supplierId, $pfId, $this->userId);
        self::assertSame(CashSettlementService::CREATED, $first['status']);
        self::assertNotNull($first['doc_number']);
        self::assertSame('paid', $this->purchaseStatus($pfId));

        // Peněžní noha na ANALYTICE pokladny (211.100), ne na ploché 211.
        $byAcc = $this->linesByAccountCode($this->entryOf((int) $first['cash_document_id']));
        self::assertEqualsWithDelta(3000.00, $byAcc['321']['debit'], 0.001);
        self::assertEqualsWithDelta(3000.00, $byAcc['211.100']['credit'], 0.001);
        self::assertArrayNotHasKey('211', $byAcc);

        // Druhé uložení téhož dokladu nesmí založit druhý VPD.
        $second = $this->settlement->syncPurchase($this->supplierId, $pfId, $this->userId);
        self::assertSame(CashSettlementService::UNCHANGED, $second['status']);
        self::assertSame($first['cash_document_id'], $second['cash_document_id']);
        self::assertSame(1, $this->cashDocCount('purchase_invoice_id', $pfId));
    }

    public function testPurchaseWithoutRegisterPostsNothing(): void
    {
        $this->makeRegister('211.100');
        $pfId = $this->purchaseInvoice('FP-2', $this->client('Dodavatel a.s.', false, true), 3000.00);
        // Forma úhrady „Hotově", ale pokladna nevybraná → nic se nesmí stát.
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET payment_method = 'cash' WHERE id = ?")
            ->execute([$pfId]);

        $res = $this->settlement->syncPurchase($this->supplierId, $pfId, $this->userId);
        self::assertSame(CashSettlementService::NOOP, $res['status']);
        self::assertSame(0, $this->cashDocCount('purchase_invoice_id', $pfId));
        self::assertSame('received', $this->purchaseStatus($pfId));
    }

    public function testUnsettingRegisterRemovesVoucherAndJournalEntry(): void
    {
        $register = $this->makeRegister('211.100');
        $pfId = $this->purchaseInvoice('FP-3', $this->client('Dodavatel a.s.', false, true), 1210.00);
        $this->chooseRegister('purchase_invoices', $pfId, $register);

        $created = $this->settlement->syncPurchase($this->supplierId, $pfId, $this->userId);
        $entryId = $this->entryOf((int) $created['cash_document_id']);

        // Uživatel volbu odebral → vyrovnání se zruší. H-6: doklad s VYDANÝM číslem se
        // stornuje (protizápis, doklad zůstane v evidenci), aby v číselné řadě nevznikla
        // díra; „zaplaceno" i tak zmizí.
        $this->chooseRegister('purchase_invoices', $pfId, null);
        $removed = $this->settlement->syncPurchase($this->supplierId, $pfId, $this->userId);

        self::assertSame(CashSettlementService::REMOVED, $removed['status']);
        self::assertSame(0, $this->postedCashDocCount('purchase_invoice_id', $pfId), 'Aktivní (posted) doklad nezůstává.');
        self::assertSame(1, $this->cashDocCount('purchase_invoice_id', $pfId), 'Stornovaný doklad zůstává v evidenci (§ 11 ZoÚ).');
        self::assertSame('reversed', $this->cashDocStatus((int) $created['cash_document_id']));
        self::assertNotNull($this->journal->find($entryId, $this->supplierId), 'Původní zápis zůstává, doplní se protizápis.');
        self::assertNotNull($this->reversalEntryOf((int) $created['cash_document_id']));
        self::assertSame('booked', $this->purchaseStatus($pfId));
    }

    public function testChangingRegisterMovesVoucherInsteadOfDuplicating(): void
    {
        $first  = $this->makeRegister('211.100');
        $second = $this->makeRegister('211.200');
        $pfId = $this->purchaseInvoice('FP-4', $this->client('Dodavatel a.s.', false, true), 500.00);

        $this->chooseRegister('purchase_invoices', $pfId, $first);
        $this->settlement->syncPurchase($this->supplierId, $pfId, $this->userId);

        $this->chooseRegister('purchase_invoices', $pfId, $second);
        $moved = $this->settlement->syncPurchase($this->supplierId, $pfId, $this->userId);

        self::assertSame(CashSettlementService::CREATED, $moved['status']);
        // H-6: starý doklad se stornuje (zůstává v řadě), aktivní je právě jeden.
        self::assertSame(1, $this->postedCashDocCount('purchase_invoice_id', $pfId));
        self::assertSame(2, $this->cashDocCount('purchase_invoice_id', $pfId));
        $byAcc = $this->linesByAccountCode($this->entryOf((int) $moved['cash_document_id']));
        self::assertEqualsWithDelta(500.00, $byAcc['211.200']['credit'], 0.001);
    }

    public function testDraftPurchaseIsSkippedNotPosted(): void
    {
        $register = $this->makeRegister('211.100');
        $pfId = $this->purchaseInvoice('FP-5', $this->client('Dodavatel a.s.', false, true), 700.00);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'draft' WHERE id = ?")->execute([$pfId]);
        $this->chooseRegister('purchase_invoices', $pfId, $register);

        $res = $this->settlement->syncPurchase($this->supplierId, $pfId, $this->userId);
        self::assertSame(CashSettlementService::SKIPPED, $res['status']);
        self::assertSame('document_not_payable', $res['reason']);
        self::assertSame(0, $this->cashDocCount('purchase_invoice_id', $pfId));
    }

    public function testClosedPeriodBlocksSettlement(): void
    {
        $register = $this->makeRegister('211.100');
        $pfId = $this->purchaseInvoice('FP-6', $this->client('Dodavatel a.s.', false, true), 900.00);
        $this->chooseRegister('purchase_invoices', $pfId, $register);
        $this->db->pdo()->prepare(
            "UPDATE accounting_periods SET status = 'approved' WHERE supplier_id = ? AND fiscal_year = ?"
        )->execute([$this->supplierId, self::YEAR]);

        $res = $this->settlement->maybeSettle($this->supplierId, 'purchase_invoice', $pfId, $this->userId);
        self::assertSame(CashSettlementService::FAILED, $res['status']);
        self::assertSame('period_not_open', $res['reason']);
        // Nic zaúčtovaného a faktura zůstává neuhrazená. Rozpracovaný (draft) doklad tu
        // po neúspěšném postu zůstat MŮŽE — CashDocumentService rolluje jen transakci,
        // kterou si sám otevřel, a tenhle test běží uvnitř své vlastní. V aplikaci hook
        // běží až po commitu Action, takže si transakci vlastní a odrolluje se celý.
        self::assertSame(0, $this->postedCashDocCount('purchase_invoice_id', $pfId));
        self::assertSame('received', $this->purchaseStatus($pfId));
    }

    public function testManualCashVoucherIsNeverRemovedBySettlement(): void
    {
        $register = $this->makeRegister('211.100');
        $pfId = $this->purchaseInvoice('FP-7', $this->client('Dodavatel a.s.', false, true), 400.00);
        // Ruční pokladní doklad z modulu Pokladna (auto_settlement = 0) na tutéž fakturu.
        $this->db->pdo()->prepare(
            "INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, description,
                 vat_mode, total_amount, purchase_invoice_id, status)
             VALUES (?, ?, 'out', 'purchase_payment', 'VPD-RUCNI', ?, 'Ruční doklad', 'none', 400.00, ?, 'posted')"
        )->execute([$this->supplierId, $register, self::YEAR . '-06-10', $pfId]);

        // Faktura hotovostní vyrovnání nemá zvolené → sync ho nesmí smazat.
        $res = $this->settlement->syncPurchase($this->supplierId, $pfId, $this->userId);
        self::assertSame(CashSettlementService::NOOP, $res['status']);
        self::assertSame(1, $this->cashDocCount('purchase_invoice_id', $pfId));
    }

    // ── vydaná faktura (PPD) ────────────────────────────────────────────────────

    public function testInvoiceSettlementCreatesReceiptAndMarksPaid(): void
    {
        $register = $this->makeRegister('211.100');
        $invoiceId = $this->saleInvoice('FV-1', $this->client('Odběratel s.r.o.', true, false), 2420.00);
        $this->chooseRegister('invoices', $invoiceId, $register);

        $res = $this->settlement->syncInvoice($this->supplierId, $invoiceId, $this->userId);
        self::assertSame(CashSettlementService::CREATED, $res['status']);

        $byAcc = $this->linesByAccountCode($this->entryOf((int) $res['cash_document_id']));
        self::assertEqualsWithDelta(2420.00, $byAcc['211.100']['debit'], 0.001);
        self::assertEqualsWithDelta(2420.00, $byAcc['311']['credit'], 0.001);

        $row = $this->invoiceRow($invoiceId);
        self::assertSame('paid', (string) $row['status']);
        self::assertEqualsWithDelta(2420.00, (float) $row['paid_total'], 0.001);

        // Opakované uložení nesmí vyrobit druhou úhradu.
        $again = $this->settlement->syncInvoice($this->supplierId, $invoiceId, $this->userId);
        self::assertSame(CashSettlementService::UNCHANGED, $again['status']);
        self::assertCount(1, $this->paymentRows($invoiceId));
    }

    public function testInvoiceUnsettingRemovesPaymentAndVoucher(): void
    {
        $register = $this->makeRegister('211.100');
        $invoiceId = $this->saleInvoice('FV-2', $this->client('Odběratel s.r.o.', true, false), 1000.00);
        $this->chooseRegister('invoices', $invoiceId, $register);
        $created = $this->settlement->syncInvoice($this->supplierId, $invoiceId, $this->userId);
        $entryId = $this->entryOf((int) $created['cash_document_id']);

        $this->chooseRegister('invoices', $invoiceId, null);
        $removed = $this->settlement->syncInvoice($this->supplierId, $invoiceId, $this->userId);

        self::assertSame(CashSettlementService::REMOVED, $removed['status']);
        // H-6: storno místo smazání — aktivní doklad žádný, stornovaný v evidenci zůstává.
        self::assertSame(0, $this->postedCashDocCount('invoice_id', $invoiceId));
        self::assertSame(1, $this->cashDocCount('invoice_id', $invoiceId));
        self::assertSame('reversed', $this->cashDocStatus((int) $created['cash_document_id']));
        self::assertNotNull($this->journal->find($entryId, $this->supplierId));
        self::assertNotNull($this->reversalEntryOf((int) $created['cash_document_id']));
        self::assertSame([], $this->paymentRows($invoiceId));
        self::assertEqualsWithDelta(0.0, (float) $this->invoiceRow($invoiceId)['paid_total'], 0.001);
    }

    public function testProformaIsNotSettleable(): void
    {
        $register = $this->makeRegister('211.100');
        $invoiceId = $this->saleInvoice('ZF-1', $this->client('Odběratel s.r.o.', true, false), 12100.00, 'proforma');
        $this->chooseRegister('invoices', $invoiceId, $register);

        $res = $this->settlement->syncInvoice($this->supplierId, $invoiceId, $this->userId);
        self::assertSame(CashSettlementService::SKIPPED, $res['status']);
        self::assertSame('document_type_not_settleable', $res['reason']);
        self::assertSame(0, $this->cashDocCount('invoice_id', $invoiceId));
    }

    public function testForeignRegisterIsRefusedWithReason(): void
    {
        $this->seedCashAnalytic('211.900');
        $register = $this->registers->create($this->supplierId, [
            'name' => 'Valutová', 'account_code' => '211.900', 'currency_code' => 'EUR',
        ]);
        $invoiceId = $this->saleInvoice('FV-3', $this->client('Odběratel s.r.o.', true, false), 1000.00);
        $this->chooseRegister('invoices', $invoiceId, $register);

        $res = $this->settlement->syncInvoice($this->supplierId, $invoiceId, $this->userId);
        self::assertSame(CashSettlementService::SKIPPED, $res['status']);
        self::assertSame('register_not_czk', $res['reason']);
    }

    public function testDetachRemovesSettlementRegardlessOfChoice(): void
    {
        $register = $this->makeRegister('211.100');
        $invoiceId = $this->saleInvoice('FV-4', $this->client('Odběratel s.r.o.', true, false), 605.00);
        $this->chooseRegister('invoices', $invoiceId, $register);
        $this->settlement->syncInvoice($this->supplierId, $invoiceId, $this->userId);

        // Volba na dokladu zůstává, přesto storno faktury vyrovnání zruší (H-6: stornem).
        self::assertTrue($this->settlement->detach($this->supplierId, 'invoice', $invoiceId));
        self::assertSame(0, $this->postedCashDocCount('invoice_id', $invoiceId));
        self::assertSame(1, $this->cashDocCount('invoice_id', $invoiceId));
        self::assertFalse($this->settlement->detach($this->supplierId, 'invoice', $invoiceId));
    }

    // ── pomocné ─────────────────────────────────────────────────────────────────

    /** Pokladna na vlastní analytice 211.NNN (osnovu doplníme jako migrace 1322). */
    private function makeRegister(string $code): int
    {
        $this->seedCashAnalytic($code);

        return $this->registers->create($this->supplierId, [
            'name' => 'Pokladna ' . $code, 'account_code' => $code,
        ]);
    }

    private function seedCashAnalytic(string $code): void
    {
        $this->db->pdo()->prepare(
            'INSERT IGNORE INTO chart_of_accounts
                (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
             SELECT p.supplier_id, ?, ?, p.account_type, p.normal_side, 0, p.id, 1
               FROM chart_of_accounts p
              WHERE p.supplier_id = ? AND p.account_code = ? AND p.is_synthetic = 1'
        )->execute([$code, 'Pokladna ' . $code, $this->supplierId, '211']);
    }

    private function chooseRegister(string $table, int $id, ?int $registerId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE {$table} SET payment_method = 'cash', cash_register_id = ? WHERE id = ?"
        )->execute([$registerId, $id]);
    }

    private function entryOf(int $cashDocumentId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT journal_entry_id FROM cash_documents WHERE id = ?');
        $stmt->execute([$cashDocumentId]);
        return (int) $stmt->fetchColumn();
    }

    private function cashDocCount(string $column, int $documentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM cash_documents WHERE supplier_id = ? AND {$column} = ?"
        );
        $stmt->execute([$this->supplierId, $documentId]);
        return (int) $stmt->fetchColumn();
    }

    private function cashDocStatus(int $cashDocumentId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM cash_documents WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$cashDocumentId, $this->supplierId]);
        return (string) $stmt->fetchColumn();
    }

    private function reversalEntryOf(int $cashDocumentId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT reversal_entry_id FROM cash_documents WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$cashDocumentId, $this->supplierId]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null) ? null : (int) $v;
    }

    private function postedCashDocCount(string $column, int $documentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM cash_documents
              WHERE supplier_id = ? AND {$column} = ? AND status = 'posted'"
        );
        $stmt->execute([$this->supplierId, $documentId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, array{debit:float, credit:float}> */
    private function linesByAccountCode(int $entryId): array
    {
        $entry = $this->journal->find($entryId, $this->supplierId);
        $out = [];
        foreach ($entry['lines'] as $l) {
            $code = $this->code((int) $l['account_id']);
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][$l['side']] += (float) $l['amount'];
        }
        return $out;
    }

    private function code(int $accountId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT account_code FROM chart_of_accounts WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$accountId, $this->supplierId]);
        $v = $stmt->fetchColumn();
        return $v === false ? '?' : (string) $v;
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

    private function saleInvoice(string $varsymbol, int $clientId, float $total, string $type = 'invoice'): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0, ?, 0, "issued", "1", ?)'
        );
        $issue = self::YEAR . '-06-10';
        $stmt->execute([
            $this->supplierId, $varsymbol, $type, $clientId, $issue, $issue, $issue,
            $this->currencyId, $total, $total, $this->userId,
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
