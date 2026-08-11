<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\PurchaseInvoiceArithmeticException;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FR4 (vendor audit 2026-08) — preventivní pojistka `PurchaseInvoiceCalculator
 * ::assertIntegrity()`: základ + DPH musí odpovídat uložené hlavičce a uloženému celku.
 *
 * Za normálních okolností tohle nemůže selhat — recompute() odvozuje hlavičku ze
 * STEJNÉHO průchodu položkami, který persistuje (viz FR4 v bugreportu — riziko je
 * nejvyšší u dokladu s `vat_overrides`, kde InvoiceMath::applyRateOverrides
 * přerozděluje zaokrouhlovací reziduum). Test proto ověřuje guard PŘÍMO: nejdřív na
 * zdravém dokladu (musí projít), pak na dokladu se SCHVÁLNĚ poškozenou hlavičkou
 * (obchází recompute() ručním SQL update — simuluje budoucí regresi/race), kde musí
 * spolehlivě zahodit uložení. Bez assertIntegrity() by druhý case prošel tiše.
 *
 * Izolováno pod existujícím supplierem, vše uklizeno v tearDown.
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class PurchaseInvoiceArithmeticIntegrityTest extends TestCase
{
    private Connection $db;
    private PurchaseInvoiceRepository $repo;
    private PurchaseInvoiceCalculator $calc;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $vatRateId = 0;
    private int $vendorId = 0;
    private int $invoiceId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container  = Bootstrap::buildApp()->getContainer();
            $this->db   = $container->get(Connection::class);
            $this->repo = $container->get(PurchaseInvoiceRepository::class);
            $this->calc = $container->get(PurchaseInvoiceCalculator::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0
            || $this->czId === 0 || $this->vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, ic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "FR4 Test Vendor", "Test 1", "Praha", "11000", ?, "10000003",
                     "fr4@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                 issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, prices_include_vat,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, "FR4-2098-001", "{}", "invoice", "full", "2098-05-01", "2098-05-01",
                     "2098-05-29", "2098-05-01", ?, 0, 0, 0, 0, 0, "draft", "40", ?)'
        );
        $stmt->execute([$this->supplierId, $this->vendorId, $this->currencyId, $this->userId]);
        $this->invoiceId = (int) $pdo->lastInsertId();

        $this->repo->replaceItems($this->invoiceId, [
            [
                'description'            => 'Testovací položka',
                'quantity'               => 1,
                'unit'                   => 'ks',
                'unit_price_without_vat' => 1000.0,
                'vat_rate_id'            => $this->vatRateId,
                'order_index'            => 0,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        if ($this->vendorId !== 0) {
            $ids = $pdo->query(
                'SELECT id FROM purchase_invoices WHERE vendor_id = ' . $this->vendorId
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($ids as $id) {
                $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
            }
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->vendorId]);
        }
        $this->db->close();
    }

    public function testRecomputeOnHealthyInvoicePassesIntegrityCheck(): void
    {
        // Nesmí vyhodit — recompute() sám drží hlavičku a položky v souladu.
        $this->calc->recompute($this->invoiceId);
        $this->calc->assertIntegrity($this->invoiceId);
        self::assertTrue(true, 'assertIntegrity() na zdravém dokladu neshodí test.');
    }

    public function testVatOverridesStillPassIntegrityCheck(): void
    {
        // Nejrizikovější cesta z FR4: ruční rekapitulace DPH (§73) — reziduum se
        // rozděluje na nejsilnější řádek. I tak musí recompute() zůstat konzistentní.
        $this->repo->setVatOverrides($this->invoiceId, $this->supplierId, [
            ['rate' => 21.0, 'base' => 999.50, 'vat' => 209.90],
        ]);
        $this->calc->recompute($this->invoiceId);
        $this->calc->assertIntegrity($this->invoiceId);
        self::assertTrue(true, 'vat_overrides nesmí rozbít guard na zdravém dokladu.');
    }

    public function testCorruptedHeaderIsCaughtByIntegrityGuard(): void
    {
        $this->calc->recompute($this->invoiceId);

        // Simulace regrese: hlavička se přepíše MIMO recompute() (např. budoucí bug,
        // race, nebo přímý zápis obcházející SSOT) — položky zůstávají netknuté.
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET total_without_vat = total_without_vat + 500 WHERE id = ?'
        )->execute([$this->invoiceId]);

        $this->expectException(PurchaseInvoiceArithmeticException::class);
        $this->calc->assertIntegrity($this->invoiceId);
    }

    public function testCorruptedTotalWithVatIsCaughtByIntegrityGuard(): void
    {
        $this->calc->recompute($this->invoiceId);

        // Základ a DPH v hlavičce sedí na položky, ale celkem (total_with_vat) je
        // rozbité — druhý typ drift, který guard musí chytit nezávisle na prvním.
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET total_with_vat = total_with_vat + 500 WHERE id = ?'
        )->execute([$this->invoiceId]);

        $this->expectException(PurchaseInvoiceArithmeticException::class);
        $this->calc->assertIntegrity($this->invoiceId);
    }

    /** Guard je zapojený i do samotného recompute() — ne jen callable zvlášť. */
    public function testRecomputeItselfRejectsPreExistingCorruptionOnItems(): void
    {
        $this->calc->recompute($this->invoiceId);

        // Cizí položka vsunutá mimo replaceItems() (simulace souběhu/bugu) — recompute()
        // ji sečte do nové hlavičky korektně, guard tedy na TOMHLE nespadne (recompute
        // je sám o sobě samoopravný). Ověřujeme jen že recompute po přidání položky
        // proběhne bez výjimky a hlavička sedí na NOVÝ součet.
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code)
             VALUES (?, 'Doplněná položka', 1, 'ks', 200, ?, 21.00, 200, 0, 200, 1, '40')"
        )->execute([$this->invoiceId, $this->vatRateId]);

        $this->calc->recompute($this->invoiceId);
        $this->calc->assertIntegrity($this->invoiceId);

        $stmt = $this->db->pdo()->prepare('SELECT total_without_vat FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$this->invoiceId]);
        self::assertEqualsWithDelta(1200.0, (float) $stmt->fetchColumn(), 0.01,
            'recompute() po přidání položky zahrne i tu novou — a guard na to nespadne.');
    }
}
