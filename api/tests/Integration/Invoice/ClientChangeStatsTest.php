<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Stats\StatsRecomputer;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Přesun vystavené faktury na jiného klienta musí přepočítat `client_revenue_cache`
 * OBOU stran. `recomputeForInvoiceId()` čte vazbu z už zapsaného řádku, takže sám
 * o sobě ošetří jen nového klienta — původnímu by zůstal nafouknutý invoice_count
 * i revenue.
 *
 * Izolace v roce 2099 pod existujícím supplierem; vše uklizeno v tearDown.
 * Soft-skip bez cfg.php / DB / dvou klientů.
 */
#[Group('integration')]
final class ClientChangeStatsTest extends TestCase
{
    private Connection $db;
    private UpdateInvoiceAction $action;
    private StatsRecomputer $stats;

    private int $supplierId = 0;
    private int $clientA = 0;
    private int $clientB = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private \DateTimeImmutable $date;
    /** @var int[] */
    private array $created = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db     = $c->get(Connection::class);
            $this->action = $c->get(UpdateInvoiceAction::class);
            $this->stats  = $c->get(StatsRecomputer::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }

        $clients = $pdo->query(
            "SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 2"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (count($clients) < 2) {
            $this->markTestSkipped('Test vyžaduje aspoň dva klienty.');
        }
        [$this->clientA, $this->clientB] = [(int) $clients[0], (int) $clients[1]];

        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí CZK/vat_rate/admin user.');
        }

        $this->date = new \DateTimeImmutable('2099-06-15');
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->created as $id) {
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        $this->created = [];
        // Cache dorovnej na realitu bez testovacích dokladů.
        $this->stats->recomputeForIds($this->clientA, null);
        $this->stats->recomputeForIds($this->clientB, null);
    }

    private function cachedCount(int $clientId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT invoice_count FROM client_revenue_cache WHERE client_id = ? AND currency_id = ?'
        );
        $stmt->execute([$clientId, $this->currencyId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function insertIssuedInvoice(int $clientId): int
    {
        $pdo = $this->db->pdo();
        $d = $this->date->format('Y-m-d');
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, 'issued', 100, 121, ?)"
        )->execute([
            'STATS-2099-1', $clientId, $this->supplierId, $d, $d, $d, $this->currencyId, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->created[] = $id;
        return $id;
    }

    private function forcePut(int $id, array $body): Psr7Response
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/invoices/' . $id)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withQueryParams(['force' => '1'])
            ->withParsedBody($body);
        return ($this->action)($req, new Psr7Response(), ['id' => (string) $id]);
    }

    public function testMovingInvoiceToAnotherClientRecomputesBothCaches(): void
    {
        // Baseline z čerstvě přepočtené cache (v DB můžou být ostatní doklady obou klientů).
        $this->stats->recomputeForIds($this->clientA, null);
        $this->stats->recomputeForIds($this->clientB, null);
        $baseA = $this->cachedCount($this->clientA);
        $baseB = $this->cachedCount($this->clientB);

        $id = $this->insertIssuedInvoice($this->clientA);
        $this->stats->recomputeForInvoiceId($id);
        self::assertSame($baseA + 1, $this->cachedCount($this->clientA), 'Klient A má doklad v cache.');

        $resp = $this->forcePut($id, [
            'invoice_type' => 'invoice',
            'client_id'    => $this->clientB,
            'currency_id'  => $this->currencyId,
            'issue_date'   => $this->date->format('Y-m-d'),
            'due_date'     => $this->date->modify('+14 days')->format('Y-m-d'),
            'tax_date'     => $this->date->format('Y-m-d'),
            'items'        => [[
                'description'            => 'Test položka',
                'quantity'               => 1,
                'unit'                   => 'ks',
                'unit_price_without_vat' => 100,
                'vat_rate_id'            => $this->vatRateId,
            ]],
        ]);
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());

        self::assertSame(
            $this->clientB,
            (int) $this->db->pdo()->query("SELECT client_id FROM invoices WHERE id = {$id}")->fetchColumn(),
            'Faktura se přesunula na klienta B.',
        );
        self::assertSame($baseB + 1, $this->cachedCount($this->clientB), 'Nový klient doklad započítal.');
        self::assertSame($baseA, $this->cachedCount($this->clientA), 'Původnímu klientovi doklad ubyl.');
    }
}
