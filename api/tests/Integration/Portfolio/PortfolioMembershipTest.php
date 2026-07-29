<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Portfolio;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Portfolio\PortfolioAggregationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přehled firem pro účetní kancelář (Fáze F, audit 2026-07 P2/M) — musí vrátit
 * JEN firmy z `user_suppliers` přihlášeného uživatele (tenant/membership izolace,
 * stejná sémantika jako SupplierAccessResolver F0):
 *   - membership {A}      → vidí jen A (ne B ani jiné existující firmy)
 *   - bez membership řádků → BC, vidí vše (accountant/readonly)
 *   - role 'admin'         → vidí vše bez ohledu na vlastní membership řádky
 *
 * Fixtures: vlastní test useři + druhý supplier (existující nebo založený
 * šablonou podle prvního, mirror SupplierMembershipTest). Po sobě uklízí.
 */
#[Group('integration')]
final class PortfolioMembershipTest extends TestCase
{
    private Connection $db;
    private PortfolioAggregationService $portfolio;

    private int $supplierA = 0;
    private int $supplierB = 0;
    private bool $createdSupplierB = false;
    private int $currencyB = 0;

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->portfolio = $c->get(PortfolioAggregationService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $has = $this->db->pdo()->query("SHOW TABLES LIKE 'roles'")->fetchColumn();
        if ($has === false) {
            $this->markTestSkipped('Dynamické role chybí — spusť api/bin/migrate.php.');
        }

        $this->supplierA = (int) $this->db->pdo()->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($this->supplierA <= 0) {
            $this->markTestSkipped('Žádný supplier v DB.');
        }

        $second = $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1 OFFSET 1')->fetchColumn();
        if ($second !== false && $second !== null) {
            $this->supplierB = (int) $second;
        } else {
            $stmt = $this->db->pdo()->prepare(
                "INSERT INTO supplier (company_name, display_name, street, city, zip, country_id,
                                       is_vat_payer, email, default_currency_id, default_vat_rate_id,
                                       default_payment_due_days, default_hourly_rate)
                 SELECT '__TEST PORTFOLIO supplier B', '__TEST PORTFOLIO supplier B', street, city, zip, country_id,
                        0, email, default_currency_id, default_vat_rate_id,
                        default_payment_due_days, default_hourly_rate
                   FROM supplier WHERE id = ?"
            );
            $stmt->execute([$this->supplierA]);
            $this->supplierB = (int) $this->db->pdo()->lastInsertId();
            $this->createdSupplierB = true;

            $cur = $this->db->pdo()->prepare(
                "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                 VALUES (?, 'CZK', 'CZK — test', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
            );
            $cur->execute([$this->supplierB]);
            $this->currencyB = (int) $this->db->pdo()->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();

        if ($this->userIds !== []) {
            $place = implode(',', array_fill(0, count($this->userIds), '?'));
            $pdo->prepare("DELETE FROM user_suppliers WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM users WHERE id IN ($place)")->execute($this->userIds);
        }
        if ($this->createdSupplierB && $this->supplierB > 0) {
            if ($this->currencyB > 0) {
                $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$this->currencyB]);
            }
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierB]);
        }
        $this->userIds = [];
    }

    private function mkUser(string $role): int
    {
        $email = '__test_portfolio_' . bin2hex(random_bytes(6)) . '@example.com';
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
             VALUES (?, '\$2y\$10\$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', '__TEST Portfolio', ?, 'cs', 1)"
        );
        $stmt->execute([$email, $this->roleId($role)]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->userIds[] = $id;
        return $id;
    }

    /** @param list<int> $supplierIds */
    private function assign(int $userId, array $supplierIds): void
    {
        $ins = $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, NULL)');
        foreach ($supplierIds as $sid) {
            $ins->execute([$userId, $sid]);
        }
    }

    public function testMembershipRestrictsToAssignedSuppliersOnly(): void
    {
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [$this->supplierA]);

        $res = $this->portfolio->overview($userId, false, new \DateTimeImmutable());
        $ids = array_map(static fn (array $c) => $c['supplier_id'], $res['companies']);

        self::assertContains($this->supplierA, $ids, 'Přiřazená firma A musí být v přehledu.');
        self::assertNotContains($this->supplierB, $ids, 'Firma B mimo membership NESMÍ uniknout do přehledu.');
        self::assertCount(1, $ids, 'S membershipem {A} vidí uživatel jen jednu firmu.');
    }

    public function testUserWithoutMembershipSeesNoCompanies(): void
    {
        $userId = $this->mkUser('accountant');
        // Žádné membership řádky.

        $res = $this->portfolio->overview($userId, false, new \DateTimeImmutable());
        $ids = array_map(static fn (array $c) => $c['supplier_id'], $res['companies']);

        self::assertSame([], $ids, 'Non-superadmin bez membershipu nesmí vidět žádnou firmu.');
    }

    public function testGlobalAdminSeesAllCompaniesDespiteOwnMembership(): void
    {
        $adminId = $this->mkUser('admin');
        $this->assign($adminId, [$this->supplierA]);

        $res = $this->portfolio->overview($adminId, true, new \DateTimeImmutable());
        $ids = array_map(static fn (array $c) => $c['supplier_id'], $res['companies']);

        self::assertContains($this->supplierA, $ids);
        self::assertContains($this->supplierB, $ids, 'Globální admin vidí i firmu mimo vlastní membership.');
    }

    public function testCompanyRowHasExpectedShape(): void
    {
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [$this->supplierA]);

        $res = $this->portfolio->overview($userId, false, new \DateTimeImmutable());
        self::assertCount(1, $res['companies']);
        $row = $res['companies'][0];

        self::assertSame($this->supplierA, $row['supplier_id']);
        self::assertArrayHasKey('company_name', $row);
        self::assertArrayHasKey('is_vat_payer', $row);
        self::assertArrayHasKey('accounting_mode', $row);
        self::assertArrayHasKey('next_deadline', $row);
        self::assertArrayHasKey('unbooked_documents', $row);
        self::assertArrayHasKey('unmatched_bank_transactions', $row);
        self::assertArrayHasKey('purchase_drafts', $row);
        self::assertArrayHasKey('period_status', $row);
        self::assertArrayHasKey('last_bank_import_at', $row);
        self::assertSame($res['total'], count($res['companies']));
    }

    private function roleId(string $legacy): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM roles WHERE system_key = ?');
        $stmt->execute([$legacy === 'admin' ? 'superadmin' : $legacy]);
        return (int) $stmt->fetchColumn();
    }
}
