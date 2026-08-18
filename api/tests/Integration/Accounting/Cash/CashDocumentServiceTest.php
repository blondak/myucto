<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Cash;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashException;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy CashDocumentService (mini-epic POKLADNA #14, §7.2). Podvojnost
 * per účel, přesná rovnost DPH rekapitulace (O6), řady, idempotence dvojkliku,
 * úhrady FV/FP, storno + cleanup, zavřené období, tenant izolace, převody,
 * zůstatek/warning. Vše v transakci → rollback.
 */
#[Group('integration')]
final class CashDocumentServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2099;

    private Connection $db;
    private CashDocumentService $service;
    private CashRegisterService $registers;
    private JournalEntryRepository $journal;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db        = $container->get(Connection::class);
            $this->service   = $container->get(CashDocumentService::class);
            $this->registers = $container->get(CashRegisterService::class);
            $this->journal   = $container->get(JournalEntryRepository::class);
            $this->periods   = $container->get(AccountingPeriodRepository::class);
            $seeder          = $container->get(ChartOfAccountsSeeder::class);
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
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    public function testDoubleEntryWithVatBreakdown(): void
    {
        $reg = $this->makeRegister();

        $sale = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 1210.00, 'vat_mode' => 'vat',
            'partner_dic' => 'CZ12345678',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 1000.00, 'vat_amount' => 210.00]],
        ], $reg), $this->userId);
        self::assertSame('posted', $sale['status']);

        $byAcc = $this->linesByAccountCode($sale['journal_entry_id']);
        self::assertEqualsWithDelta(1210.00, $byAcc['211']['debit'], 0.001);
        self::assertEqualsWithDelta(1000.00, $byAcc['602']['credit'], 0.001);
        self::assertEqualsWithDelta(210.00, $byAcc['343.200']['credit'], 0.001);

        $purchase = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 1210.00, 'vat_mode' => 'vat',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 1000.00, 'vat_amount' => 210.00]],
        ], $reg), $this->userId);
        $byAcc = $this->linesByAccountCode($purchase['journal_entry_id']);
        self::assertEqualsWithDelta(1000.00, $byAcc['501']['debit'], 0.001);
        self::assertEqualsWithDelta(210.00, $byAcc['343.100']['debit'], 0.001);
        self::assertEqualsWithDelta(1210.00, $byAcc['211']['credit'], 0.001);
    }

    public function testExactVatEqualityInsteadOfRounding(): void
    {
        $reg = $this->makeRegister();
        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 1000.00, 'vat_mode' => 'vat',
                'vat_lines' => [['vat_rate' => 21, 'base_amount' => 826.45, 'vat_amount' => 173.54]],
            ], $reg), $this->userId);
            self::fail('Nesouhlasící Σ(base+vat) musí selhat.');
        } catch (CashException $e) {
            self::assertSame('vat_lines_mismatch', $e->errorCode);
        }
        self::assertSame(0, $this->countDocuments($reg), 'Při chybě se nic nezaúčtuje ani neuloží.');
    }

    public function testMultiRateBreakdown(): void
    {
        $reg = $this->makeRegister();
        $res = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 2330.00, 'vat_mode' => 'vat',
            'vat_lines' => [
                ['vat_rate' => 21, 'base_amount' => 1000.00, 'vat_amount' => 210.00],
                ['vat_rate' => 12, 'base_amount' => 1000.00, 'vat_amount' => 120.00],
            ],
        ], $reg), $this->userId);

        $entry = $this->journal->find($res['journal_entry_id'], $this->supplierId);
        $lines343 = array_filter($entry['lines'], fn ($l) => $this->code((int) $l['account_id']) === '343.200');
        self::assertCount(2, $lines343, 'Dva řádky 343 (per sazba).');
        $byAcc = $this->linesByAccountCode($res['journal_entry_id']);
        self::assertEqualsWithDelta(330.00, $byAcc['343.200']['credit'], 0.001);
        self::assertEqualsWithDelta(2000.00, $byAcc['602']['credit'], 0.001);
    }

    public function testVatRateValidation(): void
    {
        $reg = $this->makeRegister();
        // Sazba 0.
        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 1000.00, 'vat_mode' => 'vat',
                'vat_lines' => [['vat_rate' => 0, 'base_amount' => 1000.00, 'vat_amount' => 0.00]],
            ], $reg), $this->userId);
            self::fail('Sazba 0 musí selhat.');
        } catch (CashException $e) {
            self::assertSame('vat_rate_invalid', $e->errorCode);
        }
        // Sazba mimo číselník roku.
        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 1150.00, 'vat_mode' => 'vat',
                'vat_lines' => [['vat_rate' => 15, 'base_amount' => 1000.00, 'vat_amount' => 150.00]],
            ], $reg), $this->userId);
            self::fail('Sazba mimo číselník musí selhat.');
        } catch (CashException $e) {
            self::assertSame('vat_rate_invalid', $e->errorCode);
        }
    }

    public function testVatOnlyForSaleOrPurchase(): void
    {
        $reg = $this->makeRegister();
        foreach ([['other', 'in', ['counter_account_code' => '668']], ['transfer', 'in', []]] as [$purpose, $type, $extra]) {
            try {
                $this->service->create($this->supplierId, $this->doc(array_merge([
                    'purpose' => $purpose, 'doc_type' => $type, 'total_amount' => 1210.00, 'vat_mode' => 'vat',
                    'vat_lines' => [['vat_rate' => 21, 'base_amount' => 1000.00, 'vat_amount' => 210.00]],
                ], $extra), $reg), $this->userId);
                self::fail('DPH je povolena jen pro sale/purchase (' . $purpose . ').');
            } catch (CashException $e) {
                self::assertSame('vat_purpose_not_allowed', $e->errorCode);
            }
        }
    }

    public function testPurchaseVatOver10kRejected(): void
    {
        $reg = $this->makeRegister();
        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 10000.01, 'vat_mode' => 'vat',
                'vat_lines' => [['vat_rate' => 21, 'base_amount' => 8264.46, 'vat_amount' => 1735.55]],
            ], $reg), $this->userId);
            self::fail('Daňový nákup nad 10 000 musí selhat.');
        } catch (CashException $e) {
            self::assertSame('purchase_vat_over_10k', $e->errorCode);
        }
        // Přesně 10 000 projde — § 30 ZDPH je inkluzivní a KH B.2 je až NAD práh.
        $atThreshold = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 10000.00, 'vat_mode' => 'vat',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 8264.46, 'vat_amount' => 1735.54]],
        ], $reg), $this->userId);
        self::assertSame('posted', $atThreshold['status']);
        // Těsně pod prahem projde.
        $ok = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 9999.99, 'vat_mode' => 'vat',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 8264.45, 'vat_amount' => 1735.54]],
        ], $reg), $this->userId);
        self::assertSame('posted', $ok['status']);
    }

    public function testSeriesNumbering(): void
    {
        $reg = $this->makeRegister();
        $a = $this->service->create($this->supplierId, $this->sale($reg, 1000.00), $this->userId);
        $b = $this->service->create($this->supplierId, $this->sale($reg, 1000.00), $this->userId);
        self::assertSame('PPD-2099-0001', $a['doc_number']);
        self::assertSame('PPD-2099-0002', $b['doc_number']);

        $vpd = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 500.00,
        ], $reg), $this->userId);
        self::assertSame('VPD-2099-0001', $vpd['doc_number'], 'VPD řada nezávislá na PPD.');

        $draft = $this->service->create($this->supplierId, array_merge($this->sale($reg, 300.00), ['post' => false]), $this->userId);
        self::assertNull($draft['doc_number'], 'Draft číslo nemá.');
        self::assertSame('draft', $draft['status']);
    }

    public function testCreateAndPostInOneTransactionAndDraftGuards(): void
    {
        $reg = $this->makeRegister();
        $posted = $this->service->create($this->supplierId, $this->sale($reg, 500.00), $this->userId);
        self::assertNotNull($posted['doc_number']);
        self::assertNotNull($posted['journal_entry_id']);

        $draft = $this->service->create($this->supplierId, array_merge($this->sale($reg, 500.00), ['post' => false]), $this->userId);
        self::assertNull($draft['journal_entry_id']);

        // PUT/DELETE fungují jen na draft.
        $this->service->updateDraft($this->supplierId, $draft['id'], $this->sale($reg, 700.00));
        $reloaded = $this->service->get($this->supplierId, $draft['id']);
        self::assertEqualsWithDelta(700.00, $reloaded['total_amount'], 0.001);

        try {
            $this->service->updateDraft($this->supplierId, $posted['id'], $this->sale($reg, 999.00));
            self::fail('Úprava zaúčtovaného dokladu musí selhat.');
        } catch (CashException $e) {
            self::assertSame('doc_not_draft', $e->errorCode);
        }
        try {
            $this->service->deleteDraft($this->supplierId, $posted['id']);
            self::fail('Smazání zaúčtovaného dokladu musí selhat.');
        } catch (CashException $e) {
            self::assertSame('doc_not_draft', $e->errorCode);
        }
    }

    public function testIdempotentDoublePost(): void
    {
        $reg = $this->makeRegister();
        $draft = $this->service->create($this->supplierId, array_merge($this->sale($reg, 500.00), ['post' => false]), $this->userId);

        $first = $this->service->post($this->supplierId, $draft['id'], $this->userId);
        $entry = $this->journal->find($first['journal_entry_id'], $this->supplierId);
        $versionBefore = (int) $entry['row_version'];

        $second = $this->service->post($this->supplierId, $draft['id'], $this->userId);
        self::assertSame($first['doc_number'], $second['doc_number'], 'Dvojklik → stejné číslo.');
        self::assertSame($first['journal_entry_id'], $second['journal_entry_id'], 'Dvojklik → stejný zápis.');

        $count = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId}
              AND source_type = 'cash' AND source_id = {$draft['id']}"
        )->fetchColumn();
        self::assertSame(1, $count, 'Právě jeden zápis pro doklad.');

        $entryAfter = $this->journal->find($first['journal_entry_id'], $this->supplierId);
        self::assertSame($versionBefore, (int) $entryAfter['row_version'], 'Zápis se nepřebudoval (žádný rebuild).');
    }

    public function testPartialInvoicePayment(): void
    {
        $reg = $this->makeRegister();
        $client = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-100', $client, 10000.00, $this->currencyId);

        $p1 = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 4000.00, 'invoice_id' => $invoiceId,
        ], $reg), $this->userId);
        $byAcc = $this->linesByAccountCode($p1['journal_entry_id']);
        self::assertEqualsWithDelta(4000.00, $byAcc['211']['debit'], 0.001);
        self::assertEqualsWithDelta(4000.00, $byAcc['311']['credit'], 0.001);

        $inv = $this->invoiceRow($invoiceId);
        self::assertEqualsWithDelta(4000.00, (float) $inv['paid_total'], 0.001);
        self::assertNotSame('paid', $inv['status']);
        $payments = $this->paymentRows($invoiceId);
        self::assertCount(1, $payments);
        self::assertSame('cash', $payments[0]['source']);

        $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 6000.00, 'invoice_id' => $invoiceId,
        ], $reg), $this->userId);
        $inv = $this->invoiceRow($invoiceId);
        self::assertEqualsWithDelta(10000.00, (float) $inv['paid_total'], 0.001);
        self::assertSame('paid', $inv['status']);
    }

    public function testCashProformaPaymentCreatesSameDraftDocumentsAsBank(): void
    {
        $reg = $this->makeRegister();
        $client = $this->client('Odběratel zálohy s.r.o.', true, false);
        $proformaId = $this->proforma('PRO-2099-EP8', $client, 12100.00);

        $partial = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 6050.00, 'invoice_id' => $proformaId,
        ], $reg), $this->userId);
        self::assertSame('posted', $partial['status']);
        $paymentId = (int) $this->db->pdo()->query(
            "SELECT invoice_payment_id FROM cash_documents WHERE id = {$partial['id']}"
        )->fetchColumn();
        self::assertGreaterThan(0, $paymentId);

        $taxDocId = $this->db->pdo()->query(
            "SELECT tax_document_invoice_id FROM invoice_payments WHERE id = {$paymentId}"
        )->fetchColumn();
        if ($taxDocId !== false && $taxDocId !== null) {
            self::assertSame('draft', $this->db->pdo()->query(
                'SELECT status FROM invoices WHERE id = ' . (int) $taxDocId
            )->fetchColumn());
        }

        $full = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 6050.00, 'invoice_id' => $proformaId,
        ], $reg), $this->userId);
        self::assertSame('posted', $full['status']);
        self::assertSame(1, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM invoices WHERE parent_invoice_id = {$proformaId}
              AND invoice_type = 'invoice' AND status = 'draft'"
        )->fetchColumn(), 'Plná hotovostní úhrada vytvoří právě jeden draft finálu.');
    }

    public function testInvoicePaymentOverpayAndForeignCurrency(): void
    {
        $reg = $this->makeRegister();
        $client = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-101', $client, 1000.00, $this->currencyId);
        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 2000.00, 'invoice_id' => $invoiceId,
            ], $reg), $this->userId);
            self::fail('Přeplatek musí selhat.');
        } catch (CashException $e) {
            self::assertSame('amount_exceeds_remaining', $e->errorCode);
        }

        $eurId = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId === 0) {
            self::markTestSkipped('Měna EUR není v číselníku.');
        }
        $eurInvoice = $this->saleInvoice('FV-2099-102', $client, 100.00, $eurId);
        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 100.00, 'invoice_id' => $eurInvoice,
            ], $reg), $this->userId);
            self::fail('Úhrada cizoměnové FV musí selhat.');
        } catch (CashException $e) {
            self::assertSame('foreign_currency_invoice', $e->errorCode);
        }
    }

    public function testPurchaseInvoicePayment(): void
    {
        $reg = $this->makeRegister();
        $vendor = $this->client('Dodavatel a.s.', false, true);
        $pfId = $this->purchaseInvoice('PF-2099-100', $vendor, 3000.00, $this->currencyId);

        // Plná výše → 321/211 + status paid.
        $res = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 3000.00, 'purchase_invoice_id' => $pfId,
        ], $reg), $this->userId);
        $byAcc = $this->linesByAccountCode($res['journal_entry_id']);
        self::assertEqualsWithDelta(3000.00, $byAcc['321']['debit'], 0.001);
        self::assertEqualsWithDelta(3000.00, $byAcc['211']['credit'], 0.001);
        self::assertSame('paid', $this->purchaseStatus($pfId));

        // Částečná úhrada → chyba.
        $pf2 = $this->purchaseInvoice('PF-2099-101', $vendor, 5000.00, $this->currencyId);
        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 2500.00, 'purchase_invoice_id' => $pf2,
            ], $reg), $this->userId);
            self::fail('Částečná úhrada PF musí selhat.');
        } catch (CashException $e) {
            self::assertSame('partial_purchase_payment', $e->errorCode);
        }

        // PF v EUR → chyba.
        $eurId = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId !== 0) {
            $pfEur = $this->purchaseInvoice('PF-2099-102', $vendor, 100.00, $eurId);
            try {
                $this->service->create($this->supplierId, $this->doc([
                    'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 100.00, 'purchase_invoice_id' => $pfEur,
                ], $reg), $this->userId);
                self::fail('Úhrada cizoměnové PF musí selhat.');
            } catch (CashException $e) {
                self::assertSame('foreign_currency_invoice', $e->errorCode);
            }
        }
    }

    /**
     * DDKP NAVÁZANÝ NA ZÁLOHOVOU FAKTURU se neplatí samostatně — peníze odešly už na té
     * záloze a doklad účtuje jen 343/314, závazek na 321 nikdy nezaložil. Bez guardu
     * spadl do běžné větve a zaúčtoval fantomové 321 MD / 211 D. Zrcadlo
     * BankPostingService::ddkp_not_payable.
     */
    public function testAdvanceVatDocumentCannotBePaidInCash(): void
    {
        $reg = $this->makeRegister();
        $vendor = $this->client('Dodavatel DDKP a.s.', false, true);
        $advanceId = $this->purchaseInvoice('PF-2099-109', $vendor, 12100.00, $this->currencyId);
        $pfId = $this->purchaseInvoice('PF-2099-110', $vendor, 2100.00, $this->currencyId);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind = 'advance' WHERE id = ?")
            ->execute([$advanceId]);
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET document_kind = 'tax_document', parent_purchase_invoice_id = ? WHERE id = ?"
        )->execute([$advanceId, $pfId]);

        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 2100.00,
                'purchase_invoice_id' => $pfId,
            ], $reg), $this->userId);
            self::fail('Úhrada DDKP hotově musí selhat.');
        } catch (CashException $e) {
            self::assertSame('ddkp_not_payable', $e->errorCode);
        }
    }

    /**
     * SAMOSTATNÝ daňový doklad k platbě (bez zálohové faktury) se naopak zaplatit DÁ —
     * je to jediný doklad, který k té platbě existuje. Účtuje se jako záloha na 314,
     * kam pak přijde i odpočet DPH z téhož dokladu; zbylý základ čeká na konečnou fakturu.
     *
     * Dokud tahle větev chyběla, platba spadla na `ddkp_not_payable`, doklad zůstal
     * neuhrazený a na 314 visel jen kredit z DPH bez protistrany.
     */
    public function testStandaloneAdvanceVatDocumentIsPaidAgainstAdvancesAccount(): void
    {
        $reg = $this->makeRegister();
        $vendor = $this->client('Dodavatel karta s.r.o.', false, true);
        $pfId = $this->purchaseInvoice('PF-2099-111', $vendor, 2100.00, $this->currencyId);
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET document_kind = 'tax_document', parent_purchase_invoice_id = NULL WHERE id = ?"
        )->execute([$pfId]);

        $doc = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 2100.00,
            'purchase_invoice_id' => $pfId,
        ], $reg), $this->userId);

        $stmt = $this->db->pdo()->prepare(
            'SELECT ca.account_code, l.side FROM journal_entry_lines l
               JOIN chart_of_accounts ca ON ca.id = l.account_id
               JOIN journal_entries e ON e.id = l.entry_id
              WHERE e.source_type = ? AND e.source_id = ? ORDER BY ca.account_code'
        );
        $stmt->execute(['cash', (int) $doc['id']]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $debit = array_values(array_filter($rows, static fn (array $r): bool => $r['side'] === 'debit'));

        self::assertNotEmpty($debit, 'Úhrada musí vzniknout.');
        self::assertStringStartsWith('314', (string) $debit[0]['account_code'],
            'Platba samostatného DDKP je poskytnutá záloha, ne závazek na 321.');
    }

    /** Našeptávač k úhradě hotově nesmí DDKP vůbec nabídnout. */
    public function testUnpaidSearchExcludesAdvanceVatDocument(): void
    {
        $vendor = $this->client('Dodavatel hledani s.r.o.', false, true);
        $plain = $this->purchaseInvoice('PF-2099-120', $vendor, 1000.00, $this->currencyId);
        $ddkp  = $this->purchaseInvoice('PF-2099-121', $vendor, 2100.00, $this->currencyId);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind = 'tax_document' WHERE id = ?")
            ->execute([$ddkp]);

        $found = $this->service->searchUnpaid($this->supplierId, 'purchase_invoice', 'PF-2099-12');
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $found);

        self::assertContains($plain, $ids, 'Běžná PF se nabídnout má.');
        self::assertNotContains($ddkp, $ids, 'DDKP není platební cíl — v našeptávači být nesmí.');
    }

    /**
     * H-1: platebním cílem přijaté faktury kryté zálohou je ZBYTEK (`amount_to_pay`
     * = brutto − advance_paid_amount), ne brutto. Dřív se porovnávalo proti brutto:
     * správná částka skončila na `partial_purchase_payment` a přijala se ta, která
     * na 321 nadělá fantomový debetní zůstatek ve výši zálohy.
     */
    public function testPurchasePaymentUsesRemainingAfterAdvance(): void
    {
        $reg = $this->makeRegister();
        $vendor = $this->client('Dodavatel se zalohou s.r.o.', false, true);
        $pfId = $this->purchaseInvoice('PF-2099-130', $vendor, 121000.00, $this->currencyId);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET advance_paid_amount = 100000.00 WHERE id = ?')
            ->execute([$pfId]);

        // Našeptávač musí ukázat zbytek 21 000, ne brutto 121 000.
        $found = $this->service->searchUnpaid($this->supplierId, 'purchase_invoice', 'PF-2099-130');
        self::assertCount(1, $found);
        self::assertEqualsWithDelta(21000.00, (float) $found[0]['remaining'], 0.001, 'H-1: zbývá po záloze 21 000.');
        self::assertEqualsWithDelta(100000.00, (float) $found[0]['paid'], 0.001, 'H-1: `paid` se počítá, nevrací se 0.');

        // Brutto (121 000) je nově chyba — na 321 tolik závazku nikdy nebylo.
        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 121000.00,
                'purchase_invoice_id' => $pfId,
            ], $reg), $this->userId);
            self::fail('Úhrada v plném brutto přes zaplacenou zálohu musí selhat.');
        } catch (CashException $e) {
            self::assertSame('partial_purchase_payment', $e->errorCode);
        }

        // Zbytek 21 000 projde a zaúčtuje se 321 MD / 211 D právě na zbytek.
        $res = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 21000.00,
            'purchase_invoice_id' => $pfId,
        ], $reg), $this->userId);
        $byAcc = $this->linesByAccountCode($res['journal_entry_id']);
        self::assertEqualsWithDelta(21000.00, $byAcc['321']['debit'], 0.001);
        self::assertEqualsWithDelta(21000.00, $byAcc['211']['credit'], 0.001);
        self::assertSame('paid', $this->purchaseStatus($pfId));
    }

    /** H-1: faktura plně krytá zálohou (amount_to_pay = 0) se v našeptávači nesmí objevit. */
    public function testUnpaidSearchExcludesFullyAdvancedPurchaseInvoice(): void
    {
        $vendor = $this->client('Dodavatel plne kryty s.r.o.', false, true);
        $covered = $this->purchaseInvoice('PF-2099-131', $vendor, 12100.00, $this->currencyId);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET advance_paid_amount = 12100.00 WHERE id = ?')
            ->execute([$covered]);

        $found = $this->service->searchUnpaid($this->supplierId, 'purchase_invoice', 'PF-2099-131');

        self::assertSame([], $found, 'PF na 0 Kč po záloze nemá a nemá mít pokladní úhradu.');
    }

    /**
     * H-2: storno bez zadaného data se NESMÍ datovat dneškem. `PostingService::reverse()`
     * (B8) je stavěný tak, že bez explicitního data použije datum původního zápisu a na
     * dnešek ho posune až u zamčeného období. Pokladna dosazovala dnešek sama, takže
     * protizápis k březnovému dokladu padal do listopadu, zatímco DPH se opravila v březnu.
     */
    public function testReverseWithoutDateKeepsOriginalEntryDate(): void
    {
        $reg = $this->makeRegister();
        $sale = $this->service->create($this->supplierId, $this->sale($reg, 1500.00), $this->userId);
        $original = $this->journal->find((int) $sale['journal_entry_id'], $this->supplierId);

        $rev = $this->service->reverse($this->supplierId, $sale['id'], ['reason' => 'Chybny doklad'], $this->userId);

        $reversal = $this->journal->find((int) $rev['reversal_entry_id'], $this->supplierId);
        self::assertSame(
            substr((string) $original['entry_date'], 0, 10),
            substr((string) $reversal['entry_date'], 0, 10),
            'H-2: protizápis dědí datum původního zápisu, ne dnešek.',
        );
        self::assertNotSame(date('Y-m-d'), substr((string) $reversal['entry_date'], 0, 10));
    }

    public function testReverseWithCleanup(): void
    {
        $reg = $this->makeRegister();

        // (a) sale → protizápis + reversed.
        $sale = $this->service->create($this->supplierId, $this->sale($reg, 1000.00), $this->userId);
        $rev = $this->service->reverse($this->supplierId, $sale['id'], ['reason' => 'Chybný doklad', 'entry_date' => self::YEAR . '-06-30'], $this->userId);
        self::assertGreaterThan(0, $rev['reversal_entry_id']);
        $reversal = $this->journal->find($rev['reversal_entry_id'], $this->supplierId);
        self::assertStringContainsString('Chybný doklad', (string) $reversal['description']);
        self::assertSame('reversed', $this->docStatus($sale['id']));

        // (b) úhrada FV → deletePayment + status zpět.
        $client = $this->client('Odběratel', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-200', $client, 1000.00, $this->currencyId);
        $pay = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 1000.00, 'invoice_id' => $invoiceId,
        ], $reg), $this->userId);
        self::assertSame('paid', $this->invoiceRow($invoiceId)['status']);
        $this->service->reverse($this->supplierId, $pay['id'], ['reason' => 'Storno úhrady', 'entry_date' => self::YEAR . '-06-30'], $this->userId);
        self::assertCount(0, $this->paymentRows($invoiceId), 'Platba smazána.');
        $inv = $this->invoiceRow($invoiceId);
        self::assertEqualsWithDelta(0.0, (float) $inv['paid_total'], 0.001);
        self::assertNotSame('paid', $inv['status']);

        // (c) úhrada PF → status zpět booked.
        $vendor = $this->client('Dodavatel', false, true);
        $pfId = $this->purchaseInvoice('PF-2099-200', $vendor, 2000.00, $this->currencyId);
        $pfPay = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 2000.00, 'purchase_invoice_id' => $pfId,
        ], $reg), $this->userId);
        self::assertSame('paid', $this->purchaseStatus($pfId));
        $this->service->reverse($this->supplierId, $pfPay['id'], ['reason' => 'Storno úhrady PF', 'entry_date' => self::YEAR . '-06-30'], $this->userId);
        self::assertSame('booked', $this->purchaseStatus($pfId));

        // (d) storno storna → chyba.
        try {
            $this->service->reverse($this->supplierId, $sale['id'], ['reason' => 'Znovu', 'entry_date' => self::YEAR . '-06-30'], $this->userId);
            self::fail('Storno storna musí selhat.');
        } catch (CashException $e) {
            self::assertSame('doc_not_posted', $e->errorCode);
        }

        // (e) bez reason → chyba.
        $sale2 = $this->service->create($this->supplierId, $this->sale($reg, 100.00), $this->userId);
        try {
            $this->service->reverse($this->supplierId, $sale2['id'], ['reason' => 'ab'], $this->userId);
            self::fail('Storno bez důvodu musí selhat.');
        } catch (CashException $e) {
            self::assertSame('reason_required', $e->errorCode);
        }
    }

    public function testDeleteDocumentRemovesJournalEntries(): void
    {
        $reg = $this->makeRegister();

        // (a) zaúčtovaný doklad → zmizí doklad i jeho zápis v deníku.
        $sale = $this->service->create($this->supplierId, $this->sale($reg, 1000.00), $this->userId);
        $entryId = (int) $this->service->get($this->supplierId, $sale['id'])['journal_entry_id'];
        $res = $this->service->deleteDocument($this->supplierId, $sale['id']);
        self::assertSame([$entryId], $res['deleted_entry_ids']);
        self::assertNull($this->journal->find($entryId, $this->supplierId));
        self::assertSame(0, $this->countDocuments($reg));

        // (b) stornovaný doklad → zmizí i protizápis.
        $sale2 = $this->service->create($this->supplierId, $this->sale($reg, 500.00), $this->userId);
        $entry2 = (int) $this->service->get($this->supplierId, $sale2['id'])['journal_entry_id'];
        $rev = $this->service->reverse($this->supplierId, $sale2['id'], ['reason' => 'Chybný doklad', 'entry_date' => self::YEAR . '-06-30'], $this->userId);
        $res2 = $this->service->deleteDocument($this->supplierId, $sale2['id']);
        self::assertEqualsCanonicalizing([$entry2, (int) $rev['reversal_entry_id']], $res2['deleted_entry_ids']);
        self::assertNull($this->journal->find($entry2, $this->supplierId));
        self::assertNull($this->journal->find((int) $rev['reversal_entry_id'], $this->supplierId));

        // (c) úhrada FV → platba se odmaže a faktura přestane být zaplacená.
        $client = $this->client('Odběratel', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-300', $client, 1000.00, $this->currencyId);
        $pay = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 1000.00, 'invoice_id' => $invoiceId,
        ], $reg), $this->userId);
        self::assertSame('paid', $this->invoiceRow($invoiceId)['status']);
        $this->service->deleteDocument($this->supplierId, $pay['id']);
        self::assertCount(0, $this->paymentRows($invoiceId), 'Platba smazána.');
        self::assertNotSame('paid', $this->invoiceRow($invoiceId)['status']);

        // (d) neexistující doklad → 404.
        try {
            $this->service->deleteDocument($this->supplierId, $sale['id']);
            self::fail('Smazání neexistujícího dokladu musí selhat.');
        } catch (CashException $e) {
            self::assertSame('validation', $e->errorCode);
        }
    }

    public function testDeleteDocumentInClosedPeriodRefused(): void
    {
        $reg = $this->makeRegister();
        $posted = $this->service->create($this->supplierId, $this->sale($reg, 500.00), $this->userId);
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        try {
            $this->service->deleteDocument($this->supplierId, $posted['id']);
            self::fail('Smazání v zavřeném období musí selhat.');
        } catch (CashException $e) {
            self::assertSame('period_not_open', $e->errorCode);
        }
        self::assertSame(1, $this->countDocuments($reg));
    }

    public function testClosedPeriodRefused(): void
    {
        $reg = $this->makeRegister();
        $posted = $this->service->create($this->supplierId, $this->sale($reg, 500.00), $this->userId);

        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        // Post do zavřeného období.
        $this->expectException(PostingException::class);
        $this->service->create($this->supplierId, $this->sale($reg, 600.00), $this->userId);
    }

    public function testReverseInClosedPeriodRefused(): void
    {
        $reg = $this->makeRegister();
        $posted = $this->service->create($this->supplierId, $this->sale($reg, 500.00), $this->userId);
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        $this->expectException(PostingException::class);
        $this->service->reverse($this->supplierId, $posted['id'], ['reason' => 'Storno', 'entry_date' => self::YEAR . '-06-30'], $this->userId);
    }

    public function testTenantIsolation(): void
    {
        $reg = $this->makeRegister();
        $draft = $this->service->create($this->supplierId, array_merge($this->sale($reg, 500.00), ['post' => false]), $this->userId);
        $other = $this->supplierId + 99999;

        try {
            $this->service->get($other, $draft['id']);
            self::fail('Doklad cizí firmy nesmí být viditelný.');
        } catch (CashException $e) {
            self::assertSame('validation', $e->errorCode);
            self::assertSame(404, $e->httpStatus);
        }
        try {
            $this->service->post($other, $draft['id'], $this->userId);
            self::fail('Doklad cizí firmy nesmí jít zaúčtovat.');
        } catch (CashException $e) {
            self::assertSame(404, $e->httpStatus);
        }
        // Úhrada faktury, která firmě nepatří / neexistuje → invoice_not_found.
        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 100.00, 'invoice_id' => 999999999,
            ], $reg), $this->userId);
            self::fail('Úhrada neexistující/cizí FV musí selhat.');
        } catch (CashException $e) {
            self::assertSame('invoice_not_found', $e->errorCode);
        }
    }

    public function testTransferLegsThrough261(): void
    {
        $reg = $this->makeRegister();
        $in = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'transfer', 'doc_type' => 'in', 'total_amount' => 5000.00,
        ], $reg), $this->userId);
        $byAcc = $this->linesByAccountCode($in['journal_entry_id']);
        self::assertEqualsWithDelta(5000.00, $byAcc['211']['debit'], 0.001);
        self::assertEqualsWithDelta(5000.00, $byAcc['261']['credit'], 0.001);

        $out = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'transfer', 'doc_type' => 'out', 'total_amount' => 2000.00,
        ], $reg), $this->userId);
        $byAcc = $this->linesByAccountCode($out['journal_entry_id']);
        self::assertEqualsWithDelta(2000.00, $byAcc['261']['debit'], 0.001);
        self::assertEqualsWithDelta(2000.00, $byAcc['211']['credit'], 0.001);
    }

    public function testBalanceFromLedgerAndNegativeWarning(): void
    {
        $reg = $this->makeRegister();
        // Záporný zůstatek → warning, post projde (R7).
        $out = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'other', 'doc_type' => 'out', 'total_amount' => 500.00, 'counter_account_code' => '648',
        ], $reg), $this->userId);
        self::assertContains('cash.warning.negative_balance', $out['warnings']);
        self::assertSame('posted', $out['status']);

        // Doplnění příjmem → zůstatek z ledgeru = 1000 − 500 = 500.
        $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'other', 'doc_type' => 'in', 'total_amount' => 1000.00, 'counter_account_code' => '668',
        ], $reg), $this->userId);
        $balance = $this->registers->balance($this->supplierId, '211', self::YEAR . '-12-31');
        self::assertEqualsWithDelta(500.00, $balance, 0.001, 'Zůstatek = obrat analytiky v ledgeru (R6).');
    }

    public function testForeignCashRegisterAutoPostingWithCnbRateAndDualBalance(): void
    {
        // Kurz ČNB naseedovaný do cache → getRate() nesahá na síť.
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.00);
        $reg = $this->registers->create($this->supplierId, [
            'name' => 'EUR pokladna', 'account_code' => '211500', 'currency_code' => 'EUR', 'is_default' => true,
        ]);

        // Příjem 100 EUR × 25 = 2 500 CZK (bez zadaného kurzu → ČNB).
        $sale = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'sale', 'doc_type' => 'in', 'amount_foreign' => 100.00,
        ], $reg), $this->userId);
        self::assertSame('posted', $sale['status']);

        $byAcc = $this->linesByAccountCode($sale['journal_entry_id']);
        self::assertEqualsWithDelta(2500.00, $byAcc['211500']['debit'], 0.001, 'CZK ekvivalent = amount_foreign × kurz ČNB.');
        self::assertEqualsWithDelta(2500.00, $byAcc['602']['credit'], 0.001);

        // Cizoměnová stopa na řádku analytiky pokladny (nosič přecenění §24/6).
        $entry = $this->journal->find($sale['journal_entry_id'], $this->supplierId);
        $cashLine = null;
        foreach ($entry['lines'] as $l) {
            if ($this->code((int) $l['account_id']) === '211500') {
                $cashLine = $l;
            }
        }
        self::assertNotNull($cashLine);
        self::assertSame('EUR', $cashLine['currency_code']);
        self::assertEqualsWithDelta(100.00, (float) $cashLine['amount_foreign'], 0.001);
        self::assertEqualsWithDelta(25.00, (float) $cashLine['fx_rate'], 0.0001);

        // Výdej 40 EUR × 25 = 1 000 CZK.
        $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase', 'doc_type' => 'out', 'amount_foreign' => 40.00,
        ], $reg), $this->userId);

        // Duální zůstatek: 60 EUR / 1 500 CZK.
        $detail = $this->registers->get($this->supplierId, $reg, self::YEAR . '-12-31');
        self::assertEqualsWithDelta(60.00, (float) $detail['balance_foreign'], 0.001, 'Cizoměnový zůstatek 100 − 40.');
        self::assertEqualsWithDelta(1500.00, (float) $detail['balance'], 0.001, 'CZK zůstatek z ledgeru 2500 − 1000.');
    }

    public function testForeignCashManualRateOverride(): void
    {
        $reg = $this->registers->create($this->supplierId, [
            'name' => 'USD pokladna', 'account_code' => '211510', 'currency_code' => 'USD',
        ]);
        // Ruční kurz 30 → 50 USD = 1 500 CZK (žádný ČNB lookup).
        $res = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase', 'doc_type' => 'out', 'amount_foreign' => 50.00, 'fx_rate' => 30.0,
        ], $reg), $this->userId);
        $byAcc = $this->linesByAccountCode($res['journal_entry_id']);
        self::assertEqualsWithDelta(1500.00, $byAcc['211510']['credit'], 0.001);
        self::assertEqualsWithDelta(1500.00, $byAcc['501']['debit'], 0.001);
    }

    public function testForeignRegisterRejectsInvoicePayment(): void
    {
        $reg = $this->registers->create($this->supplierId, [
            'name' => 'EUR pokladna', 'account_code' => '211500', 'currency_code' => 'EUR',
        ]);
        $client = $this->client('Odběratel', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-900', $client, 1000.00, $this->currencyId);
        try {
            // BEZ ručního kurzu ZÁMĚRNĚ: nepodporovaný účel musí padnout dřív, než se
            // vůbec řeší kurz ČNB (jinak by uživatel dostal technické fx_rate_unavailable
            // místo skutečného důvodu).
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'invoice_payment', 'doc_type' => 'in', 'amount_foreign' => 40.00,
                'invoice_id' => $invoiceId,
            ], $reg), $this->userId);
            self::fail('Úhrada faktury z valutové pokladny musí selhat (v1).');
        } catch (CashException $e) {
            self::assertSame('foreign_register_purpose_unsupported', $e->errorCode);
        }
    }

    public function testCzkRegisterUnchangedNoForeignTrace(): void
    {
        // Regrese: CZK pokladna beze změny — amount_foreign NULL, řádky bez cizoměnové stopy.
        $reg = $this->makeRegister();
        $sale = $this->service->create($this->supplierId, $this->sale($reg, 1210.00), $this->userId);
        $stored = $this->service->get($this->supplierId, $sale['id']);
        self::assertNull($stored['amount_foreign']);
        self::assertSame('CZK', $stored['currency_code']);
        $entry = $this->journal->find($sale['journal_entry_id'], $this->supplierId);
        foreach ($entry['lines'] as $l) {
            self::assertNull($l['currency_code'] ?? null, 'CZK doklad nemá cizoměnovou stopu.');
        }
    }

    public function testForeignSaleWithVatConvertsBreakdownToCzk(): void
    {
        // §4/12: DPH rozpad zadaný v cizí měně se převede kurzem dokladu na CZK, Σ(základ+daň)
        // sedí PŘESNĚ na total_amount CZK → doklad se založí a zaúčtuje automaticky (HIGH 1).
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.00);
        $reg = $this->registers->create($this->supplierId, [
            'name' => 'EUR pokladna', 'account_code' => '211500', 'currency_code' => 'EUR', 'is_default' => true,
        ]);
        // 100 EUR: základ 82,64 + daň 17,36 (Σ 100 EUR). × 25 = 2066,00 / 434,00 → total 2500,00 CZK.
        $sale = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'sale', 'doc_type' => 'in', 'amount_foreign' => 100.00, 'vat_mode' => 'vat',
            'partner_dic' => 'CZ12345678',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 82.64, 'vat_amount' => 17.36]],
        ], $reg), $this->userId);
        self::assertSame('posted', $sale['status'], 'Valutový DPH doklad se musí založit (žádný vat_lines_mismatch).');

        $byAcc = $this->linesByAccountCode($sale['journal_entry_id']);
        self::assertEqualsWithDelta(2500.00, $byAcc['211500']['debit'], 0.001);
        self::assertEqualsWithDelta(2066.00, $byAcc['602']['credit'], 0.001);
        self::assertEqualsWithDelta(434.00, $byAcc['343.200']['credit'], 0.001);

        // Uložený rozpad je v CZK a Σ(základ+daň) == total_amount.
        $stored = $this->service->get($this->supplierId, $sale['id']);
        self::assertEqualsWithDelta(2500.00, (float) $stored['total_amount'], 0.001);
        $sum = 0.0;
        foreach ($stored['vat_lines'] as $vl) {
            $sum += (float) $vl['base_amount'] + (float) $vl['vat_amount'];
        }
        self::assertEqualsWithDelta(2500.00, $sum, 0.001, 'Σ(základ+daň)_CZK == total_amount_CZK.');
    }

    public function testForeignRegisterRejectsOtherWithMoneyCounter(): void
    {
        // Gate účelů nesmí jít obejít přes purpose=other s protiúčtem saldokonta/peněz na cestě.
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.00);
        $reg = $this->registers->create($this->supplierId, [
            'name' => 'EUR pokladna', 'account_code' => '211500', 'currency_code' => 'EUR',
        ]);
        foreach (['311', '321', '261', '314', '324'] as $counter) {
            try {
                $this->service->create($this->supplierId, $this->doc([
                    'purpose' => 'other', 'doc_type' => 'in', 'amount_foreign' => 50.00, 'counter_account_code' => $counter,
                ], $reg), $this->userId);
                self::fail('Valutový „other" s protiúčtem ' . $counter . ' musí selhat.');
            } catch (CashException $e) {
                self::assertSame('foreign_register_purpose_unsupported', $e->errorCode, 'counter ' . $counter);
            }
        }
        // Běžný nefinanční protiúčet (648) projde.
        $ok = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'other', 'doc_type' => 'out', 'amount_foreign' => 20.00, 'counter_account_code' => '648',
        ], $reg), $this->userId);
        self::assertSame('posted', $ok['status']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seedRate(string $code, string $date, float $rate): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([$date, strtoupper($code), $rate]);
    }

    private function makeRegister(string $code = '211'): int
    {
        return $this->registers->create($this->supplierId, ['name' => 'Pokladna ' . $code, 'account_code' => $code, 'is_default' => true]);
    }

    /**
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function doc(array $over, int $registerId): array
    {
        return array_merge([
            'register_id' => $registerId,
            'issue_date'  => self::YEAR . '-06-15',
            'description' => 'Pokladní pohyb',
            'post'        => true,
        ], $over);
    }

    /** @return array<string,mixed> */
    private function sale(int $registerId, float $total): array
    {
        return $this->doc(['purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => $total], $registerId);
    }

    private function countDocuments(int $registerId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM cash_documents WHERE supplier_id = ? AND register_id = ?');
        $stmt->execute([$this->supplierId, $registerId]);
        return (int) $stmt->fetchColumn();
    }

    private function docStatus(int $id): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM cash_documents WHERE id = ?');
        $stmt->execute([$id]);
        return (string) $stmt->fetchColumn();
    }

    /**
     * @return array<string,array{debit:float,credit:float}>
     */
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

    private function saleInvoice(string $varsymbol, int $clientId, float $total, int $currencyId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, 0, ?, 0, "issued", "1", ?)'
        );
        $issue = self::YEAR . '-06-10';
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue, $currencyId, $total, $total, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function proforma(string $varsymbol, int $clientId, float $total): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, prices_include_vat, total_without_vat, total_vat,
                 total_with_vat, paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, "proforma", ?, ?, NULL, ?, ?, 0, 1, 10000, 2100, ?, 0, "issued", "1", ?)'
        );
        $date = self::YEAR . '-06-10';
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $date, $date, $this->currencyId, $total, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Záloha", 1, "ks", 10000, ?, 21, 10000, 2100, 12100, 0)'
        )->execute([$id, $this->vatRateId]);
        return $id;
    }

    private function purchaseInvoice(string $number, int $vendorId, float $total, int $currencyId): int
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
        $stmt->execute([$this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue, $currencyId, $total, $total, $this->userId]);
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
