<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Cash;

use MyInvoice\Action\Accounting\Cash\CashRegisterAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * L-1: `ensureOwnSeries()` volá `DocumentSeriesService`, který hlásí své chyby
 * `ClosingException`. `mapCashError()` znala jen `CashException`, takže
 * `series_prefix_taken` / `invalid_prefix` proletělo do `mapPostingError()`
 * a uživatel dostal HTTP 500 místo validační 422.
 */
#[Group('integration')]
final class CashRegisterSeriesErrorMappingTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private CashRegisterAction $action;
    private CashRegisterService $service;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('accounting_document_series') || !$this->db->hasTable('cash_registers')) {
            $this->markTestSkipped('Migrace pokladny / řad neproběhly.');
        }
        $this->action  = $container->get(CashRegisterAction::class);
        $this->service = $container->get(CashRegisterService::class);
        $accounts      = $container->get(ChartOfAccountsRepository::class);
        $seeder        = $container->get(ChartOfAccountsSeeder::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $seeder->seedForSupplier($this->supplierId);
        foreach (['211100', '211200'] as $code) {
            $this->seedAnalytic($accounts, $code);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testSeriesPrefixCollisionIsValidationErrorNotServerError(): void
    {
        $year = (int) date('Y');
        $pdo  = $this->db->pdo();

        // Pokladna A si vezme první volný prefix (PPD2).
        $aId = $this->service->create($this->supplierId, [
            'name' => 'A', 'account_code' => '211100', 'own_series' => true,
        ]);
        $stmt = $pdo->prepare(
            'SELECT prefix FROM accounting_document_series
              WHERE supplier_id = ? AND register_id = ? AND series_code = ? AND fiscal_year = ?'
        );
        $stmt->execute([$this->supplierId, $aId, 'cash_in', $year]);
        $takenPrefix = (string) $stmt->fetchColumn();
        self::assertNotSame('', $takenPrefix);

        // Pokladna B má z minulého roku řádek se STEJNÝM prefixem — zapnutí vlastní
        // řady ho zdědí a narazí na kolizi (ClosingException ze služby řad).
        $bId = $this->service->create($this->supplierId, ['name' => 'B', 'account_code' => '211200']);
        $pdo->prepare(
            'INSERT INTO accounting_document_series (supplier_id, series_code, fiscal_year, register_id, prefix, next_number)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$this->supplierId, 'cash_in', $year - 1, $bId, $takenPrefix]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', "/api/accounting/cash-registers/{$bId}")
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody(['own_series' => true]);

        $response = $this->action->update($request, new Response(), ['id' => (string) $bId]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('cash.error.series_prefix_taken', $this->json($response)['error']['code']);
    }

    private function seedAnalytic(ChartOfAccountsRepository $accounts, string $code): void
    {
        if ($accounts->findByCode($this->supplierId, $code) !== null) {
            return;
        }
        $parent = $accounts->findByCode($this->supplierId, '211');
        $accounts->insert($this->supplierId, [
            'code'         => $code,
            'account_code' => $code,
            'name'         => 'Pokladna ' . $code,
            'account_type' => 'asset',
            'normal_side'  => 'debit',
            'is_synthetic' => false,
            'parent_id'    => $parent !== null ? (int) $parent['id'] : null,
            'is_active'    => true,
        ]);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
