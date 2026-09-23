<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tenant;

use MyInvoice\Action\Auth\DefaultSupplierAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Výchozí firma uživatele: bez uložené volby server při požadavku bez výběru
 * firmy jednou zvolí přístupnou firmu s nejvíc doklady a uloží ji.
 *
 * Syntetické firmy v transakci (rollback v tearDown). Cizí firma má vždy
 * nejvíc dokladů, takže kdyby výběr neomezoval membership, vyhrála by ona.
 */
#[Group('integration')]
final class DefaultSupplierSelectionTest extends TestCase
{
    private ContainerInterface $container;
    private Connection $db;
    private bool $inTx = false;

    private int $czId = 0;
    private int $vatRateId = 0;
    private int $currencyId = 0;
    private int $creatorId = 0;

    /** @var array<int, array{currency:int, client:int}> */
    private array $refs = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->container = Bootstrap::buildApp()->getContainer();
            $this->db = $this->container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'roles'")->fetchColumn() === false) {
            $this->markTestSkipped('Dynamické role chybí — spusť api/bin/migrate.php.');
        }
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->creatorId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->czId === 0 || $this->vatRateId === 0 || $this->currencyId === 0 || $this->creatorId === 0) {
            $this->markTestSkipped('Chybí základní data (country/vat_rate/currency/user) v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        $this->inTx = false;
    }

    public function testPicksAccessibleCompanyWithMostDocumentsAndStoresItOnce(): void
    {
        $few = $this->supplier('__TEST VYCHOZI malo');
        $most = $this->supplier('__TEST VYCHOZI nejvic');
        $middle = $this->supplier('__TEST VYCHOZI stred');
        $foreign = $this->supplier('__TEST VYCHOZI cizi');

        $this->documents($few, 1, 0);
        $this->documents($most, 2, 3);   // 5 dokladů: vydané i přijaté se sčítají
        $this->documents($middle, 4, 0);
        $this->documents($foreign, 6, 6);

        $userId = $this->user();
        $this->assign($userId, [$few, $most, $middle]);
        self::assertNull($this->storedDefault($userId));

        self::assertSame($most, $this->resolveWithoutHeader($userId));
        self::assertSame($most, $this->storedDefault($userId), 'Zvolená firma se uloží jako výchozí.');

        // Jednorázově: další doklady v jiné firmě už výběr nezmění.
        $this->documents($middle, 5, 0);
        self::assertSame($most, $this->resolveWithoutHeader($userId));
        self::assertSame($most, $this->storedDefault($userId));
    }

    public function testManuallyStoredDefaultWins(): void
    {
        // Ručně zvolená firma má vyšší id i méně dokladů — nevyhrála by ani
        // podle nejnižšího id, ani podle počtu dokladů.
        $most = $this->supplier('__TEST VYCHOZI rucni nejvic');
        $few = $this->supplier('__TEST VYCHOZI rucni malo');
        $this->documents($few, 1, 0);
        $this->documents($most, 5, 5);

        $userId = $this->user();
        $this->assign($userId, [$few, $most]);
        $this->db->pdo()->prepare('UPDATE users SET default_supplier_id = ? WHERE id = ?')->execute([$few, $userId]);

        self::assertSame($few, $this->resolveWithoutHeader($userId));
        self::assertSame($few, $this->storedDefault($userId));
    }

    public function testInaccessibleCompanyIsNeverChosen(): void
    {
        $own = $this->supplier('__TEST VYCHOZI vlastni');
        $foreign = $this->supplier('__TEST VYCHOZI cizi plna');
        $this->documents($foreign, 8, 8);

        $userId = $this->user();
        $this->assign($userId, [$own]);
        // Uložená volba mimo membership (přístup odebrán) se nepoužije.
        $this->db->pdo()->prepare('UPDATE users SET default_supplier_id = ? WHERE id = ?')->execute([$foreign, $userId]);

        self::assertSame($own, $this->resolveWithoutHeader($userId));
        self::assertSame($own, $this->storedDefault($userId), 'Jediná přístupná firma se uloží taky.');
    }

    public function testTieGoesToLowestIdAndHeaderKeepsPrecedence(): void
    {
        $first = $this->supplier('__TEST VYCHOZI shoda 1');
        $second = $this->supplier('__TEST VYCHOZI shoda 2');
        $this->documents($first, 2, 0);
        $this->documents($second, 0, 2);

        $userId = $this->user();
        $this->assign($userId, [$first, $second]);

        $withHeader = $this->resolver()->resolve($this->request($userId)->withHeader(SupplierScopeMiddleware::HEADER_NAME, (string) $second));
        self::assertSame($second, $withHeader->supplierId);
        self::assertNull($this->storedDefault($userId), 'Explicitní výběr firmy výchozí firmu neukládá.');

        self::assertSame($first, $this->resolveWithoutHeader($userId));
    }

    public function testSwitcherEndpointStoresOnlyAccessibleCompany(): void
    {
        $a = $this->supplier('__TEST VYCHOZI endpoint A');
        $b = $this->supplier('__TEST VYCHOZI endpoint B');
        $foreign = $this->supplier('__TEST VYCHOZI endpoint cizi');
        $this->documents($a, 3, 0);

        $userId = $this->user();
        $this->assign($userId, [$a, $b]);
        $action = $this->container->get(DefaultSupplierAction::class);

        $denied = $action($this->request($userId, 'PUT')->withParsedBody(['supplier_id' => $foreign]), (new ResponseFactory())->createResponse());
        self::assertSame(403, $denied->getStatusCode());
        self::assertNull($this->storedDefault($userId));

        $ok = $action($this->request($userId, 'PUT')->withParsedBody(['supplier_id' => $b]), (new ResponseFactory())->createResponse());
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame($b, $this->storedDefault($userId));
        self::assertSame($b, $this->resolveWithoutHeader($userId), 'Volba uživatele má přednost před počtem dokladů.');
    }

    private function resolveWithoutHeader(int $userId): int
    {
        $access = $this->resolver()->resolve($this->request($userId));
        self::assertFalse($access->denied);
        return $access->supplierId;
    }

    /** Nový resolver = nový request; memo z předchozího volání nesmí výsledek zakrýt. */
    private function resolver(): SupplierAccessResolver
    {
        return $this->container->make(SupplierAccessResolver::class);
    }

    private function request(int $userId, string $method = 'GET'): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/api/auth/me')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $userId, 'role_id' => 0, 'is_superadmin' => false]);
    }

    private function storedDefault(int $userId): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT default_supplier_id FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        return $value === null || $value === false ? null : (int) $value;
    }

    private function supplier(string $name): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO supplier (company_name, display_name, street, city, zip, country_id, email,
                                   default_currency_id, default_vat_rate_id, accounting_mode, is_vat_payer)
             VALUES (?, ?, 'Testovací 1', 'Brno', '60200', ?, 'vychozi@example.invalid', ?, ?, 'tax_evidence', 0)"
        )->execute([$name, $name, $this->czId, $this->currencyId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $currency = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currency, $id]);

        $pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id)
             VALUES (?, 'Syntetický partner s.r.o.', 'Partnerská 2', 'Praha', '11000', ?, ?)"
        )->execute([$id, $this->czId, $currency]);

        $this->refs[$id] = ['currency' => $currency, 'client' => (int) $pdo->lastInsertId()];
        return $id;
    }

    private function documents(int $supplierId, int $issued, int $received): void
    {
        $pdo = $this->db->pdo();
        $inv = $pdo->prepare(
            "INSERT INTO invoices (supplier_id, invoice_type, status, client_id, issue_date, due_date, currency_id)
             VALUES (?, 'invoice', 'draft', ?, '2026-03-01', '2026-03-15', ?)"
        );
        for ($i = 0; $i < $issued; $i++) {
            $inv->execute([$supplierId, $this->refs[$supplierId]['client'], $this->refs[$supplierId]['currency']]);
        }
        $pi = $pdo->prepare(
            "INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, document_kind, status,
                                            issue_date, due_date, received_at, currency_id, vendor_snapshot, created_by)
             VALUES (?, ?, ?, 'invoice', 'received', '2026-03-01', '2026-03-15', '2026-03-02', ?, '{}', ?)"
        );
        for ($i = 0; $i < $received; $i++) {
            $pi->execute([
                $supplierId, $this->refs[$supplierId]['client'], 'PF-' . bin2hex(random_bytes(4)),
                $this->refs[$supplierId]['currency'], $this->creatorId,
            ]);
        }
    }

    private function user(): int
    {
        $pdo = $this->db->pdo();
        $role = $pdo->prepare('SELECT id FROM roles WHERE system_key = ?');
        $role->execute(['accountant']);
        $pdo->prepare(
            "INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
             VALUES (?, '\$2y\$10\$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', '__TEST Výchozí firma', ?, 'cs', 1)"
        )->execute(['__test_default_supplier_' . bin2hex(random_bytes(6)) . '@example.com', (int) $role->fetchColumn()]);
        return (int) $pdo->lastInsertId();
    }

    /** @param list<int> $supplierIds */
    private function assign(int $userId, array $supplierIds): void
    {
        $ins = $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, NULL)');
        foreach ($supplierIds as $sid) {
            $ins->execute([$userId, $sid]);
        }
    }
}
