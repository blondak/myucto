<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\VatLedgerService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Fallback klasifikace u přijatého plnění v režimu přenesení daňové povinnosti,
 * kde doklad ŽÁDNÝ klasifikační kód nenese (import z cizího systému, ruční zadání
 * bez volby kódu).
 *
 * Dřív se takový doklad bez ohledu na zemi dodavatele klasifikoval jako `5`, tedy
 * jako TUZEMSKÝ přenos podle § 92a — doklad od zahraničního dodavatele tím spadl na
 * ř. 10 přiznání a do sekce B.1 kontrolního hlášení, kam patří jen tuzemský režim.
 * Zrcadlí se proto fallback prodejní strany:
 *
 *   - dodavatel z EU     → `24e` (přijetí služby § 9/1, ř. 5, KH A.2)
 *   - dodavatel 3. země  → `24`  (ř. 12, KH A.2)
 *   - tuzemský dodavatel → `5`   (§ 92a, ř. 10, KH B.1) — beze změny
 *
 * Zboží od služby se z dat nepozná, proto je default služba a řádek nese příznak
 * `code_estimated`; pořízení zboží z EU (23) a dovoz ze 3. země (25) si uživatel
 * zvolí ručně. Doklad s VLASTNÍM kódem se fallbacku nikdy nedotkne.
 *
 * Izolovaný rok 2098 pod existujícím supplierem, úklid v tearDown.
 */
#[Group('integration')]
final class VatLedgerForeignRcFallbackTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private VatLedgerService $ledger;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;

    /** @var int[] */
    private array $vendorIds = [];
    /** @var int[] */
    private array $purchaseIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container    = Bootstrap::buildApp()->getContainer();
            $this->db     = $container->get(Connection::class);
            $this->ledger = $container->get(VatLedgerService::class);
        } catch (\Exception $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user) v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->purchaseIds !== []) {
            $ph = implode(',', array_fill(0, count($this->purchaseIds), '?'));
            $pdo->prepare("DELETE FROM purchase_invoice_items WHERE purchase_invoice_id IN ($ph)")->execute($this->purchaseIds);
            $pdo->prepare("DELETE FROM purchase_invoices WHERE id IN ($ph)")->execute($this->purchaseIds);
        }
        if ($this->vendorIds !== []) {
            $ph = implode(',', array_fill(0, count($this->vendorIds), '?'));
            $pdo->prepare("DELETE FROM clients WHERE id IN ($ph)")->execute($this->vendorIds);
        }
        $this->db->close();
    }

    public function testForeignReverseChargeWithoutCodeDoesNotFallBackToDomesticSection(): void
    {
        $euId = $this->countryId(true);
        $thirdId = $this->countryId(false);
        $czId = $this->czCountryId();
        if ($euId === 0 || $thirdId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí země (EU / mimo EU / CZ) v číselníku.');
        }

        $eu    = $this->purchaseNoCode('RC-EU', $this->vendor('RC dodavatel EU', $euId));
        $third = $this->purchaseNoCode('RC-3Z', $this->vendor('RC dodavatel 3. země', $thirdId));
        $cz    = $this->purchaseNoCode('RC-CZ', $this->vendor('RC dodavatel tuzemsko', $czId));

        $rows = $this->rowsByInvoice();

        self::assertSame('24e', $rows[$eu]['code'] ?? null, 'EU dodavatel + RC bez kódu → 24e (ř. 5, KH A.2), ne tuzemská 5.');
        self::assertSame('24', $rows[$third]['code'] ?? null, '3. země + RC bez kódu → 24 (ř. 12, KH A.2), ne tuzemská 5.');
        self::assertSame('5', $rows[$cz]['code'] ?? null, 'Tuzemský dodavatel zůstává na 5 (§ 92a) — beze změny.');

        // Odhad je jen u zahraničních; tuzemská 5 je jednoznačná.
        self::assertTrue($rows[$eu]['code_estimated'], 'Zahraniční RC bez kódu je odhad — zboží se má překlasifikovat ručně.');
        self::assertTrue($rows[$third]['code_estimated']);
        self::assertFalse($rows[$cz]['code_estimated'], 'Tuzemský přenos se neodhaduje.');
    }

    public function testExplicitCodeOnDocumentWins(): void
    {
        $euId = $this->countryId(true);
        if ($euId === 0) {
            $this->markTestSkipped('Chybí EU země v číselníku.');
        }
        // Pořízení zboží z EU — uživatel zvolil 23; fallback ho nesmí přepsat na 24e.
        $id = $this->purchaseNoCode('RC-EU-ZBOZI', $this->vendor('RC dodavatel zboží EU', $euId), '23');

        $rows = $this->rowsByInvoice();
        self::assertSame('23', $rows[$id]['code'] ?? null, 'Kód zadaný na dokladu má přednost před fallbackem.');
        self::assertFalse($rows[$id]['code_estimated'], 'Kód z dokladu není odhad.');
    }

    /** @return array<int, array<string,mixed>> invoice_id → řádek ledgeru */
    private function rowsByInvoice(): array
    {
        $rows = $this->ledger->rows(
            $this->supplierId,
            sprintf('%04d-01-01', self::YEAR),
            sprintf('%04d-12-31', self::YEAR),
            true,
        );
        $out = [];
        foreach ($rows as $r) {
            if (($r['source'] ?? '') === 'purchase') {
                $out[(int) $r['invoice_id']] = $r;
            }
        }
        return $out;
    }

    private function czCountryId(): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1");
        $stmt->execute();
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /** Libovolná země z/mimo EU kromě ČR — test nezávisí na konkrétním státu. */
    private function countryId(bool $eu): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM countries WHERE iso2 <> 'CZ' AND is_eu = ? ORDER BY id LIMIT 1"
        );
        $stmt->execute([$eu ? 1 : 0]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function vendor(string $name, int $countryId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "rc-fallback@example.test", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $countryId, $this->currencyId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->vendorIds[] = $id;
        return $id;
    }

    /** RC doklad; `$code = null` = bez klasifikace na hlavičce i položce → fallback. */
    private function purchaseNoCode(string $number, int $vendorId, ?string $code = null): int
    {
        $date = sprintf('%04d-06-15', self::YEAR);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, received_at_source, currency_id, exchange_rate, reverse_charge,
                 vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, "manual", ?, 1, 1, "{}", 1000, 0, 1000, "received", ?, "full", ?)'
        )->execute([
            $this->supplierId, $vendorId, $number, $date, $date, $date, $date,
            $this->currencyId, $code, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->purchaseIds[] = $id;

        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
             VALUES (?, "Test RC", 1, "ks", 1000, ?, 0, 1000, 0, 1000, 0, ?)'
        )->execute([$id, $this->vatRateId, $code]);

        return $id;
    }
}
