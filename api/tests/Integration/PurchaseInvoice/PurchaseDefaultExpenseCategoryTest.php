<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Výchozí kategorie nákladu dodavatele (clients.default_expense_category_id) se musí
 * aplikovat při zakládání přijaté faktury přes PurchaseInvoiceRepository::createDraft().
 *
 * createDraft() je společný choke-point pro VŠECHNY cesty zakládání přijaté faktury —
 * manuální zadání i importy (AI PDF, ISDOC/ZIP, iDoklad, Fakturoid, bankovní párování).
 * Bug: žádný z importů default neaplikoval (jen frontend watcher u manuálu). Fix je
 * centrálně v createDraft, takže ho dostanou všechny cesty stejně.
 *
 * Pravidla, která tu ověřujeme:
 *   - payload bez expense_category_id  → použije se default dodavatele
 *   - payload s explicitním expense_category_id → vyhrává explicitní volba
 *   - dodavatel bez defaultu + payload bez kategorie → zůstane NULL
 *
 * Izolováno pod existujícím supplierem, vše uklizeno v tearDown.
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class PurchaseDefaultExpenseCategoryTest extends TestCase
{
    private Connection $db;
    private PurchaseInvoiceRepository $repo;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;

    /** @var int[] */
    private array $catIds = [];
    /** @var int[] */
    private array $vendorIds = [];
    /** @var int[] */
    private array $piIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container  = Bootstrap::buildContainer();
            $this->db   = $container->get(Connection::class);
            $this->repo = $container->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->piIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->vendorIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        foreach ($this->catIds as $id) {
            $pdo->prepare('DELETE FROM expense_categories WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testVendorDefaultAppliedWhenPayloadHasNoCategory(): void
    {
        $catId  = $this->category('TST-DEF-A', 'Test default A');
        $vendor = $this->vendor('Dodavatel s defaultem', 'CZ20000001', $catId);

        $id = $this->repo->createDraft($this->payload($vendor, 'PDEC-1'), $this->userId, $this->supplierId);
        $this->piIds[] = $id;

        self::assertSame($catId, $this->storedCategory($id),
            'createDraft bez expense_category_id musí doplnit výchozí kategorii dodavatele');
    }

    public function testExplicitCategoryWinsOverVendorDefault(): void
    {
        $defaultCat  = $this->category('TST-DEF-B', 'Test default B');
        $explicitCat = $this->category('TST-EXP-B', 'Test explicit B');
        $vendor      = $this->vendor('Dodavatel s defaultem 2', 'CZ20000002', $defaultCat);

        $payload = $this->payload($vendor, 'PDEC-2');
        $payload['expense_category_id'] = $explicitCat;

        $id = $this->repo->createDraft($payload, $this->userId, $this->supplierId);
        $this->piIds[] = $id;

        self::assertSame($explicitCat, $this->storedCategory($id),
            'explicitně zvolená kategorie má přebít výchozí kategorii dodavatele');
    }

    public function testNoDefaultStaysNull(): void
    {
        $vendor = $this->vendor('Dodavatel bez defaultu', 'CZ20000003', null);

        $id = $this->repo->createDraft($this->payload($vendor, 'PDEC-3'), $this->userId, $this->supplierId);
        $this->piIds[] = $id;

        self::assertNull($this->storedCategory($id),
            'dodavatel bez defaultu + payload bez kategorie → expense_category_id zůstane NULL');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function storedCategory(int $piId): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT expense_category_id FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$piId]);
        $val = $stmt->fetchColumn();
        return $val === null || $val === false ? null : (int) $val;
    }

    private function category(string $code, string $label): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO expense_categories (supplier_id, code, label) VALUES (?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $code, $label]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->catIds[] = $id;
        return $id;
    }

    private function vendor(string $name, string $dic, ?int $defaultCategoryId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor,
                                  default_expense_category_id)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "v@example.com", "cs", ?, 0, 1, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $dic, $this->currencyId, $defaultCategoryId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->vendorIds[] = $id;
        return $id;
    }

    /** Minimální validní payload pro createDraft (povinné: vendor_invoice_number, datumy, currency). */
    private function payload(int $vendorId, string $number): array
    {
        return [
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $number,
            'document_kind'         => 'invoice',
            'issue_date'            => '2099-06-10',
            'tax_date'              => '2099-06-10',
            'due_date'              => '2099-06-24',
            'currency_id'           => $this->currencyId,
        ];
    }
}
