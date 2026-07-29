<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Cash;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Report\DphBookBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * DPH projekce daňových pokladních dokladů (mini-epic POKLADNA #14, §7.3).
 *
 * Ověřuje VatLedgerService::fetchCash() (cash řádky v rows()), NEDUPLICITU vůči
 * fakturám (R8 — doklad s invoice_id se v rows neobjeví), fix kolize group-klíčů
 * KH/Knihy DPH (HIGH A1 — cash.id × invoice.id), KH sekce A.4/A.5/B.3, limit 10k
 * u daňového nákupu a čtení sazeb z číselníku. Vše v transakci → rollback.
 */
#[Group('integration')]
final class VatLedgerCashTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2099;
    private const MONTH = 6;
    private const START = '2099-06-01';
    private const END = '2099-06-30';

    private Connection $db;
    private CashDocumentService $service;
    private CashRegisterService $registers;
    private VatLedgerService $ledger;
    private KontrolniHlaseniBuilder $kh;
    private DphBookBuilder $book;
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
            $this->ledger    = $container->get(VatLedgerService::class);
            $this->kh        = $container->get(KontrolniHlaseniBuilder::class);
            $this->book      = $container->get(DphBookBuilder::class);
            $this->periods   = $container->get(AccountingPeriodRepository::class);
            $seeder          = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $seeder->seedForSupplier($this->supplierId);
        // KH/DPHDP3 projekce předpokládá plátce (roll-back v tearDown).
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 1, is_identified = 0 WHERE id = ?')->execute([$this->supplierId]);
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

    public function testCashSaleProjectsIntoLedger(): void
    {
        $reg = $this->makeRegister();
        $this->cashDoc([
            'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 1210.00, 'vat_mode' => 'vat',
            'tax_date' => self::YEAR . '-06-15', 'partner_dic' => 'CZ12345678',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 1000.00, 'vat_amount' => 210.00]],
        ], $reg);

        $cash = $this->cashRows(self::START, self::END);
        self::assertCount(1, $cash, 'Daňový PPD se objeví v projekci právě jednou.');
        $r = $cash[0];
        self::assertSame('sale', $r['source']);
        self::assertSame('cash', $r['document_kind']);
        self::assertSame('1', $r['code'], 'Standardní sazba → uskutečněné plnění kód 1 (ř.1).');
        self::assertEqualsWithDelta(1000.00, (float) $r['base_czk'], 0.001);
        self::assertEqualsWithDelta(210.00, (float) $r['vat_czk'], 0.001);
        self::assertSame(self::YEAR . '-06-15', $r['tax_date'], 'Zařazení do období dle DUZP.');

        // Mimo období (červenec) → doklad tam není.
        self::assertCount(0, $this->cashRows('2099-07-01', '2099-07-31'), 'Doklad se nezařadí mimo své DUZP.');
    }

    public function testCashPurchaseRateCodes(): void
    {
        $reg = $this->makeRegister();
        // Snížená sazba 12 % → přijaté plnění kód 41.
        $this->cashDoc([
            'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 1120.00, 'vat_mode' => 'vat',
            'tax_date' => self::YEAR . '-06-15',
            'vat_lines' => [['vat_rate' => 12, 'base_amount' => 1000.00, 'vat_amount' => 120.00]],
        ], $reg);
        // Základní sazba 21 % → kód 40.
        $this->cashDoc([
            'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 1210.00, 'vat_mode' => 'vat',
            'tax_date' => self::YEAR . '-06-16',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 1000.00, 'vat_amount' => 210.00]],
        ], $reg);

        $codes = [];
        foreach ($this->cashRows(self::START, self::END) as $r) {
            self::assertSame('purchase', $r['source']);
            $codes[(string) $r['code']] = (float) $r['vat_rate'];
        }
        self::assertArrayHasKey('41', $codes, 'Snížená sazba přijatého plnění → kód 41 (ř.41).');
        self::assertArrayHasKey('40', $codes, 'Základní sazba přijatého plnění → kód 40 (ř.40).');
        self::assertEqualsWithDelta(12.0, $codes['41'], 0.001);
        self::assertEqualsWithDelta(21.0, $codes['40'], 0.001);
    }

    public function testInvoicePaymentNotDuplicatedInLedger(): void
    {
        $reg = $this->makeRegister();
        // Daňová vydaná faktura (21 %) → v projekci právě 1×.
        $client = $this->client('Odběratel DIČ', 'CZ11111118', customer: true);
        $invoiceId = $this->saleInvoice('2099060010', $client, '1', self::YEAR . '-06-10', self::YEAR . '-06-10', 5000.00, 1050.00);

        // Úhrada FV hotově (invoice_id set, vat_mode none) — DPH nese faktura.
        $this->cashDoc([
            'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 6050.00, 'invoice_id' => $invoiceId,
        ], $reg);

        // R8 belt & suspenders: i kdyby existoval daňový cash doklad s vazbou na
        // fakturu (nemůže vzniknout přes service), fetchCash ho vazbou vyloučí.
        $this->rawCashVat($reg, 'in', 'PPD-2099-9001', 2000.00, [[21, 1652.89, 347.11]], ['invoice_id' => $invoiceId]);

        $rows = $this->ledger->rows($this->supplierId, self::START, self::END);
        $saleForInvoice = array_filter($rows, fn ($r) => $r['source'] === 'sale' && (int) $r['invoice_id'] === $invoiceId && ($r['document_kind'] ?? null) !== 'cash');
        self::assertCount(1, $saleForInvoice, 'Vydaná faktura je v projekci právě jednou.');
        self::assertCount(0, $this->cashRows(self::START, self::END), 'Cash úhrada/vazba se v DPH projekci NEOBJEVÍ (neduplicita R8).');
    }

    public function testNonVatTransferOtherExcluded(): void
    {
        $reg = $this->makeRegister();
        // Prodej bez DPH.
        $this->cashDoc(['purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 500.00], $reg);
        // Převod.
        $this->cashDoc(['purpose' => 'transfer', 'doc_type' => 'in', 'total_amount' => 3000.00], $reg);
        // Ostatní.
        $this->cashDoc(['purpose' => 'other', 'doc_type' => 'in', 'total_amount' => 800.00, 'counter_account_code' => '668'], $reg);

        self::assertCount(0, $this->cashRows(self::START, self::END), 'vat_mode=none / transfer / other v projekci nejsou.');
    }

    public function testReversedExcludedDraftOnlyWithFlag(): void
    {
        $reg = $this->makeRegister();
        // Stornovaný daňový prodej → mimo projekci.
        $posted = $this->cashDoc([
            'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 1210.00, 'vat_mode' => 'vat',
            'tax_date' => self::YEAR . '-06-15',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 1000.00, 'vat_amount' => 210.00]],
        ], $reg);
        $this->service->reverse($this->supplierId, $posted['id'], ['reason' => 'Chybný doklad', 'entry_date' => self::YEAR . '-06-20'], $this->userId);
        self::assertCount(0, $this->cashRows(self::START, self::END, false), 'Stornovaný doklad není v projekci (posted-only).');
        self::assertCount(0, $this->cashRows(self::START, self::END, true), 'Stornovaný doklad není ani s draftem (status reversed).');

        // Draft daňový prodej → jen s includeDrafts (symetrie s fakturami).
        $this->cashDoc([
            'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 605.00, 'vat_mode' => 'vat',
            'tax_date' => self::YEAR . '-06-16', 'post' => false,
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 500.00, 'vat_amount' => 105.00]],
        ], $reg);
        self::assertCount(0, $this->cashRows(self::START, self::END, false), 'Draft bez includeDrafts není v projekci.');
        self::assertCount(1, $this->cashRows(self::START, self::END, true), 'Draft s includeDrafts v projekci je.');
    }

    public function testKhGroupKeyCollisionSeparatesInvoiceAndCash(): void
    {
        $reg = $this->makeRegister();

        // Vydaná faktura nad limit s DIČ → A.4, základ 20000.
        $client = $this->client('Odběratel A', 'CZ11111118', customer: true);
        $invoiceId = $this->saleInvoice('2099060020', $client, '1', self::YEAR . '-06-10', self::YEAR . '-06-10', 20000.00, 4200.00);

        // Cash prodej se SHODNÝM numerickým id → A.4, základ 15000 (jiná částka → prokáže neslití).
        $this->rawCashVat($reg, 'in', 'PPD-2099-0007', 18150.00, [[21, 15000.00, 3150.00]], [
            'id' => $invoiceId, 'partner_dic' => 'CZ22222220',
        ]);

        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $a4 = [];
        foreach ($kh->DPHKH1->VetaA4 as $v) {
            $a4[] = (string) $v['zakl_dane1'];
        }
        sort($a4);
        self::assertSame(['15000.00', '20000.00'], $a4,
            'Kolize cash.id × invoice.id: dvě samostatné věty A.4, žádné slití základů (group-klíč s document_kind).');
    }

    public function testKhSectionsForCashDocuments(): void
    {
        $reg = $this->makeRegister();

        // Prodej nad limit s DIČ → A.4.
        $a4 = $this->cashDoc([
            'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 12100.00, 'vat_mode' => 'vat',
            'tax_date' => self::YEAR . '-06-10', 'partner_dic' => 'CZ11111118',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 10000.00, 'vat_amount' => 2100.00]],
        ], $reg);
        // Prodej bez DIČ → A.5 (sumace).
        $this->cashDoc([
            'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 605.00, 'vat_mode' => 'vat',
            'tax_date' => self::YEAR . '-06-11',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 500.00, 'vat_amount' => 105.00]],
        ], $reg);
        // Nákup do limitu → B.3 (sumace).
        $this->cashDoc([
            'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 9680.00, 'vat_mode' => 'vat',
            'tax_date' => self::YEAR . '-06-12', 'partner_dic' => 'CZ22222220',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 8000.00, 'vat_amount' => 1680.00]],
        ], $reg);

        $kh = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $root = $kh->DPHKH1;

        self::assertCount(1, $root->VetaA4, 'A.4: jen prodej nad limit s DIČ.');
        self::assertSame('10000.00', (string) $root->VetaA4[0]['zakl_dane1']);
        self::assertSame($a4['doc_number'], (string) $root->VetaA4[0]['c_evid_dd'], 'c_evid_dd = naše číslo dokladu (jsme výstavce).');
        self::assertSame('500.00', (string) $root->VetaA5['zakl_dane1'], 'A.5: prodej bez DIČ do sumace.');
        self::assertSame('8000.00', (string) $root->VetaB3['zakl_dane1'], 'B.3: nákup ≤ 10k do sumace.');
    }

    public function testIdempotentCashSourceKey(): void
    {
        $reg = $this->makeRegister();
        $res = $this->cashDoc([
            'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 1210.00, 'vat_mode' => 'vat',
            'tax_date' => self::YEAR . '-06-15',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 1000.00, 'vat_amount' => 210.00]],
        ], $reg);

        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entries
              WHERE supplier_id = ? AND source_type = 'cash' AND source_id = ?"
        );
        $stmt->execute([$this->supplierId, $res['id']]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'Právě jeden zápis s klíčem (cash, doc.id).');
    }

    public function testInvoiceGroupingRegressionNeutral(): void
    {
        // Faktura se dvěma řádky téže sazby → group-klíč (rozšířený o document_kind)
        // je pro faktury neutrální: obě položky se slijí do jedné věty Knihy DPH.
        $client = $this->client('Odběratel reg', 'CZ11111118', customer: true);
        $invoiceId = $this->saleInvoice('2099060030', $client, '1', self::YEAR . '-06-10', self::YEAR . '-06-10', 10000.00, 2100.00, secondItem: [10000.00, 2100.00]);

        $book = $this->book->build($this->supplierId, self::YEAR, self::MONTH);
        $sec = [];
        foreach ($book['sections'] as $s) {
            $sec[$s['key']] = $s;
        }
        self::assertArrayHasKey('36.001', $sec, 'Vystavená tuzemská 21 % → sekce 36.001.');
        self::assertEqualsWithDelta(20000.00, $sec['36.001']['subtotal_base'], 0.01);
        $rowsForInvoice = array_filter($sec['36.001']['rows'], fn ($r) => (int) $r['invoice_id'] === $invoiceId);
        self::assertCount(1, $rowsForInvoice, 'Dva řádky téže sazby → jedna věta (grouping faktur nezměněn).');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeRegister(string $code = '211'): int
    {
        return $this->registers->create($this->supplierId, ['name' => 'Pokladna ' . $code, 'account_code' => $code, 'is_default' => true]);
    }

    /**
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function cashDoc(array $over, int $registerId): array
    {
        return $this->service->create($this->supplierId, array_merge([
            'register_id' => $registerId,
            'issue_date'  => self::YEAR . '-06-15',
            'description' => 'Pokladní pohyb',
            'post'        => true,
        ], $over), $this->userId);
    }

    /** @return list<array<string,mixed>> cash řádky projekce. */
    private function cashRows(string $from, string $to, bool $drafts = false): array
    {
        return array_values(array_filter(
            $this->ledger->rows($this->supplierId, $from, $to, $drafts),
            fn ($r) => ($r['document_kind'] ?? null) === 'cash'
        ));
    }

    /**
     * Raw daňový cash doklad (obchází service) — pro kolizní/neduplicitní scénáře,
     * kde potřebuji explicitní id / vazbu, kterou service nepovolí.
     *
     * @param list<array{0:float,1:float,2:float}> $vatLines [rate, base, vat]
     * @param array<string,mixed> $over
     */
    private function rawCashVat(int $registerId, string $docType, string $docNumber, float $total, array $vatLines, array $over = []): int
    {
        $pdo = $this->db->pdo();
        $cols = array_merge([
            'supplier_id' => $this->supplierId, 'register_id' => $registerId, 'doc_type' => $docType,
            'purpose' => $docType === 'in' ? 'sale' : 'purchase', 'doc_number' => $docNumber,
            'issue_date' => self::YEAR . '-06-15', 'tax_date' => self::YEAR . '-06-15',
            'description' => 'Raw cash', 'vat_mode' => 'vat', 'total_amount' => $total, 'status' => 'posted',
        ], $over);
        $names = array_keys($cols);
        $place = implode(', ', array_fill(0, count($names), '?'));
        $pdo->prepare('INSERT INTO cash_documents (' . implode(', ', $names) . ') VALUES (' . $place . ')')
            ->execute(array_values($cols));
        $id = isset($cols['id']) ? (int) $cols['id'] : (int) $pdo->lastInsertId();

        $vl = $pdo->prepare('INSERT INTO cash_document_vat_lines (cash_document_id, vat_rate, base_amount, vat_amount) VALUES (?, ?, ?, ?)');
        foreach ($vatLines as [$rate, $base, $vat]) {
            $vl->execute([$id, $rate, $base, $vat]);
        }
        return $id;
    }

    private function client(string $name, ?string $dic, bool $customer = false, bool $vendor = false): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "test@example.com", "cs", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $dic, $this->currencyId, $customer ? 1 : 0, $vendor ? 1 : 0]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Vydaná faktura + položka(y) — projekce přes fetchSales (JOIN invoice_items).
     *
     * @param array{0:float,1:float}|null $secondItem
     */
    private function saleInvoice(string $varsymbol, int $clientId, ?string $code, string $issue, string $tax, float $base, float $vat, ?array $secondItem = null): int
    {
        $items = [[$base, $vat]];
        if ($secondItem !== null) {
            $items[] = $secondItem;
        }
        [$sumBase, $sumVat] = [array_sum(array_column($items, 0)), array_sum(array_column($items, 1))];
        $with = $sumBase + $sumVat;
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, ?, ?, 0, "issued", ?, ?)'
        );
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $issue, $tax, $issue, $this->currencyId,
            $sumBase, $sumVat, $with, $code, $this->userId]);
        $id = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Test položka", 1, "ks", ?, ?, 21, ?, ?, ?, ?)'
        );
        foreach ($items as $i => [$b, $v]) {
            $ins->execute([$id, $b, $this->vatRateId, $b, $v, $b + $v, $i]);
        }
        return $id;
    }
}
