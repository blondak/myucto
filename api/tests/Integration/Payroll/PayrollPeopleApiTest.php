<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollPeopleAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollPeopleApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollPeopleAction $action;
    private int $userId;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $otherEmployeeId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollPeopleAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        if (!$this->db->hasTable('payroll_employee_profiles')
            || !$this->db->hasTable('payroll_employments')) {
            $this->markTestSkipped('Migrace 1188 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            "UPDATE supplier
                SET payroll_enabled = 1, accounting_mode = 'double_entry'
              WHERE id IN (?, ?)"
        )->execute([$this->supplierId, $this->otherSupplierId]);

        $insertEmployee = $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, 1, 1, 0, ?, 0, 1)'
        );
        $insertEmployee->execute([
            $this->supplierId,
            'Testovací zaměstnanec',
            'managing_partner',
            'hpp',
            42_000,
        ]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $insertEmployee->execute([
            $this->otherSupplierId,
            'Cizí testovací zaměstnanec',
            'employee',
            'hpp',
            35_000,
        ]);
        $this->otherEmployeeId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO payroll_employee_profiles (supplier_id, employee_id, profile_status)
             VALUES (?, ?, 'legacy')"
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            "INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, 'legacy', 'partner_dependent', 'active', 4200000, 1)"
        )->execute([$this->supplierId, $this->employeeId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testLegacyEmployeeIsReturnedOnceInBothAccountingModesWithoutSensitiveData(): void
    {
        foreach (['double_entry', 'tax_evidence'] as $mode) {
            $this->db->pdo()->prepare(
                'UPDATE supplier SET accounting_mode = ? WHERE id = ?'
            )->execute([$mode, $this->supplierId]);

            $listResponse = $this->action->list(
                $this->request('GET', '/api/payroll/people', 'accountant'),
                new Response(),
            );
            self::assertSame(200, $listResponse->getStatusCode());
            $list = $this->json($listResponse);
            self::assertCount(1, $list['items']);
            self::assertSame($this->employeeId, $list['items'][0]['id']);
            self::assertSame(['partner_dependent'], $list['items'][0]['relation_types']);
            self::assertSame('legacy', $list['items'][0]['profile_status']);
            $this->assertNoSensitiveFields($list);

            $detailResponse = $this->action->detail(
                $this->request('GET', "/api/payroll/people/{$this->employeeId}", 'accountant'),
                new Response(),
                ['id' => (string) $this->employeeId],
            );
            self::assertSame(200, $detailResponse->getStatusCode());
            $detail = $this->json($detailResponse);
            self::assertSame($this->employeeId, $detail['person']['id']);
            self::assertCount(1, $detail['person']['employments']);
            self::assertSame([
                'gross_debit' => '522',
                'gross_credit' => '366',
                'employer_insurance_debit' => '524',
                'employer_insurance_credit' => '336',
            ], $detail['person']['employments'][0]['accounting']);
            $this->assertNoSensitiveFields($detail);
        }
    }

    public function testDetailCannotCrossTenantBoundary(): void
    {
        $response = $this->action->detail(
            $this->request('GET', "/api/payroll/people/{$this->otherEmployeeId}", 'accountant'),
            new Response(),
            ['id' => (string) $this->otherEmployeeId],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->json($response)['error']['code']);
    }

    public function testListIncludesEmployeeWithoutProfileOrEmployment(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, 0, 0, 0, NULL, 0, 1)'
        )->execute([
            $this->supplierId,
            'Testovací zaměstnanec bez profilu',
            'employee',
            'dpp',
        ]);
        $employeeWithoutProfileId = (int) $this->db->pdo()->lastInsertId();

        $response = $this->action->list(
            $this->request('GET', '/api/payroll/people', 'accountant'),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode());
        $items = $this->json($response)['items'];
        self::assertCount(2, $items);
        $byId = array_column($items, null, 'id');
        self::assertArrayHasKey($employeeWithoutProfileId, $byId);
        self::assertSame('missing', $byId[$employeeWithoutProfileId]['profile_status']);
        self::assertSame(0, $byId[$employeeWithoutProfileId]['employment_count']);
        self::assertSame([], $byId[$employeeWithoutProfileId]['relation_types']);
        self::assertTrue($byId[$employeeWithoutProfileId]['needs_setup']);
    }

    public function testEndpointRejectsClientBearerAndDisabledPayroll(): void
    {
        $clientResponse = $this->action->list(
            $this->request('GET', '/api/payroll/people', 'client'),
            new Response(),
        );
        self::assertSame(403, $clientResponse->getStatusCode());
        self::assertSame('forbidden', $this->json($clientResponse)['error']['code']);

        $bearerResponse = $this->action->list(
            $this->request('GET', '/api/payroll/people', 'accountant', 'bearer'),
            new Response(),
        );
        self::assertSame(403, $bearerResponse->getStatusCode());
        self::assertSame('session_required', $this->json($bearerResponse)['error']['code']);

        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_enabled = 0 WHERE id = ?'
        )->execute([$this->supplierId]);
        $disabledResponse = $this->action->list(
            $this->request('GET', '/api/payroll/people', 'accountant'),
            new Response(),
        );
        self::assertSame(403, $disabledResponse->getStatusCode());
        self::assertSame('payroll_disabled', $this->json($disabledResponse)['error']['code']);
    }

    public function testDatabaseAllowsOnlyOneLegacyProjectionPerEmployee(): void
    {
        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, 'legacy-second', 'statutory_body', 'active', 4300000, 1)"
        )->execute([$this->supplierId, $this->employeeId]);
    }

    private function request(
        string $method,
        string $path,
        string $role,
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /** @param array<string,mixed> $payload */
    private function assertNoSensitiveFields(array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('"birth_number"', $json);
        self::assertStringNotContainsString('"address"', $json);
    }
}
