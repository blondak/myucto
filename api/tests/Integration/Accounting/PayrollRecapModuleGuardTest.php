<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\PayrollAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Mzdovou rekapitulaci nelze použít na měsíc, který už zpracovává modul Mzdy.
 *
 * Obě cesty účtují mzdu na tytéž účty a jedna o druhé neví, takže by mzda
 * seděla v deníku dvakrát — a idempotence rekapitulace na RRRRMM proti tomu
 * nepomůže, protože hlídá jen vlastní zápisy. Období PŘED přechodem musí zůstat
 * otevřená: do modulu se přechází uprostřed roku a starší měsíce se dál opravují
 * tam, kde vznikly. Ve stavu `setup` se ještě nic nepočítá, takže se neblokuje.
 */
#[Group('integration')]
final class PayrollRecapModuleGuardTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollAction $action;
    private int $supplierId = 0;
    private int $userId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasTable('payroll_module_state')) {
            $this->markTestSkipped('Mzdový modul ve schématu není.');
        }

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

    public function testPostIsRefusedForPeriodTakenOverByPayrollModule(): void
    {
        $this->moduleState('active', '2026-08-01');

        $response = $this->post(2026, 8);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $error = $this->json($response)['error'];
        self::assertSame('payroll_module_active', $error['code']);
        self::assertStringContainsString('08/2026', $error['message'], 'Hláška musí měsíc jmenovat.');
    }

    public function testPeriodBeforeTakeoverStaysOpenInTheRecap(): void
    {
        $this->moduleState('active', '2026-08-01');

        // Zaúčtování samo může selhat z jiných důvodů (účtový rozvrh, stav
        // období) — test tvrdí jen to, že ho neblokuje mzdový modul.
        $error = $this->json($this->post(2026, 7))['error'] ?? null;
        self::assertNotSame('payroll_module_active', $error['code'] ?? null);
    }

    public function testSetupStateDoesNotBlockTheRecapYet(): void
    {
        $this->moduleState('setup', '2026-08-01');

        $error = $this->json($this->post(2026, 8))['error'] ?? null;
        self::assertNotSame('payroll_module_active', $error['code'] ?? null);
    }

    private function moduleState(string $status, string $startPeriod): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, row_version)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE status = VALUES(status), start_period = VALUES(start_period)'
        )->execute([$this->supplierId, $status, $startPeriod]);
    }

    private function post(int $year, int $month): Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/accounting/payroll/post')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody([
                'year' => $year,
                'month' => $month,
                'gross' => 45000,
                'taxpayer_type' => 'managing_partner',
            ]);

        return $this->action->post($request, new Response());
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
