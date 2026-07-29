<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační test heuristiky auto-návrhu dohadných položek pasivních (K10 /
 * ClosingService::estimatesSuggest). Vzor: opakující se měsíční náklad dodavatele,
 * jehož faktura za POSLEDNÍ měsíc období k rozvahovému dni chybí → návrh dohadu 389
 * = průměr posledních N faktur; protiúčet = nejčastější 5xx MD účet dodavatele.
 *
 * Izolovaný supplier v transakci s rollbackem (vzor ClosingProvisionsIncomeTaxTest),
 * soft-skip bez cfg.php. Read-only preview — nic se neúčtuje.
 */
#[Group('integration')]
final class ClosingEstimatesSuggestTest extends TestCase
{
    private const YEAR = 2096;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private PostingService $posting;
    private ClosingService $closing;
    private AccountingPeriodRepository $periods;

    /** Pořadí čísla přijaté faktury — viz postedPurchase(). */
    private static int $purchaseSeq = 0;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $currencyId = 0;
    private int $czId = 0;
    private int $vatRateId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db      = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->closing = $container->get(ClosingService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        );
        $stmt->execute(['K10 dohady test s.r.o.', $this->czId, 'k10-estimates@example.com', $this->currencyId, $this->vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);
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

    public function testSuggestsEstimateForRecurringVendorMissingLastMonth(): void
    {
        // Dodavatel s pravidelnou měsíční fakturou leden–listopad (11 měsíců), prosinec CHYBÍ.
        // Průměr netto posledních 3 (zář/říj/lis): (10000 + 12000 + 11000) / 3 = 11000.
        $vendorId = $this->vendor('Energie a.s.');
        $nets = [1 => 9000.0, 2 => 9500.0, 3 => 10000.0, 4 => 10500.0, 5 => 11000.0,
                 6 => 9800.0, 7 => 10200.0, 8 => 10600.0, 9 => 10000.0, 10 => 12000.0, 11 => 11000.0];
        foreach ($nets as $month => $net) {
            $this->postedPurchase($vendorId, self::YEAR . '-' . sprintf('%02d', $month) . '-15', $net, '518');
        }

        $preview = $this->closing->estimatesSuggest($this->supplierId, $this->periodId);
        $item = $this->findVendor($preview['items'], $vendorId);

        self::assertNotNull($item, 'Opakující se náklad s chybějící prosincovou fakturou musí být v návrhu.');
        self::assertSame('estimate.liability', $item['rule_key']);
        self::assertSame('recurring_missing_last_month', $item['reason']);
        self::assertSame(11, $item['months_present']);
        self::assertSame(3, $item['sample_count']);
        self::assertEqualsWithDelta(11000.0, $item['suggested_amount'], 0.001, 'Návrh = průměr netto posledních 3 faktur.');
        self::assertSame('518', $item['counter_account'], 'Protiúčet = nejčastější 5xx MD účet dodavatele.');
        self::assertSame('2096-12', $preview['rules']['target_month']);
    }

    public function testNoSuggestionWhenLastMonthInvoicePresent(): void
    {
        // Stejná kadence, ale prosinec DORAZIL → žádný dohad (náklad je již podchycen fakturou).
        $vendorId = $this->vendor('Nájem s.r.o.');
        for ($month = 1; $month <= 12; $month++) {
            $this->postedPurchase($vendorId, self::YEAR . '-' . sprintf('%02d', $month) . '-05', 20000.0, '518');
        }

        $preview = $this->closing->estimatesSuggest($this->supplierId, $this->periodId);
        self::assertNull($this->findVendor($preview['items'], $vendorId),
            'Dodavatel s fakturou i za poslední měsíc nesmí být navržen.');
    }

    public function testNoSuggestionForNonRecurringVendor(): void
    {
        // Jen dvě faktury za rok (pod prahem 3 měsíce) → nepravidelný, žádný dohad = žádný šum.
        $vendorId = $this->vendor('Jednorázová dodávka s.r.o.');
        $this->postedPurchase($vendorId, self::YEAR . '-03-10', 50000.0, '501');
        $this->postedPurchase($vendorId, self::YEAR . '-05-10', 50000.0, '501');

        $preview = $this->closing->estimatesSuggest($this->supplierId, $this->periodId);
        self::assertNull($this->findVendor($preview['items'], $vendorId),
            'Nepravidelný dodavatel pod prahem opakování nesmí generovat návrh.');
    }

    public function testNoSuggestionWhenCadenceStoppedBeforeLastMonth(): void
    {
        // Faktury jen leden–září, pak přestaly (říjen–prosinec nic) → není to „chybějící
        // poslední měsíc", ale ukončený vztah. Měsíc před posledním (listopad) chybí → bez návrhu.
        $vendorId = $this->vendor('Bývalý dodavatel s.r.o.');
        for ($month = 1; $month <= 9; $month++) {
            $this->postedPurchase($vendorId, self::YEAR . '-' . sprintf('%02d', $month) . '-12', 7000.0, '518');
        }

        $preview = $this->closing->estimatesSuggest($this->supplierId, $this->periodId);
        self::assertNull($this->findVendor($preview['items'], $vendorId),
            'Ukončená kadence (chybí i měsíc před posledním) nesmí generovat návrh.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function vendor(string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Ulice 1", "Praha", "11000", ?, "CZ12345678", ?, "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, 'v' . uniqid() . '@example.com', $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Přijatá faktura (received) + její zaúčtování MD 5xx / D 321 (netto bez DPH pro jednoduchost). */
    private function postedPurchase(int $vendorId, string $issueDate, float $net, string $expenseAccount): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, ?, 0, ?, "received", ?)'
        );
        // Monotonně, ne losem: uq_pi_vendor_invoice je unikátní na
        // (supplier_id, vendor_id, vendor_invoice_number, issue_date) a random_int(1000, 9999)
        // nad jedním dodavatelem a týmž datem občas kolidoval.
        $number = 'DODAV-' . substr($issueDate, 0, 7) . '-' . sprintf('%05d', ++self::$purchaseSeq);
        $stmt->execute([
            $this->supplierId, $vendorId, $number,
            $issueDate, $issueDate, $issueDate, $issueDate, $this->currencyId,
            json_encode(['name' => 'Snapshot']), $net, $net, $this->userId,
        ]);
        $piId = (int) $pdo->lastInsertId();

        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $piId, [
            ['account_code' => $expenseAccount, 'side' => 'debit', 'amount' => $net],
            ['account_code' => '321', 'side' => 'credit', 'amount' => $net],
        ], ['entry_date' => $issueDate, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        return $piId;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>|null
     */
    private function findVendor(array $items, int $vendorId): ?array
    {
        foreach ($items as $it) {
            if ((int) $it['vendor_id'] === $vendorId) {
                return $it;
            }
        }
        return null;
    }
}
