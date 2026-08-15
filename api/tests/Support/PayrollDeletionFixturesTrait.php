<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Sdílené lešení testů mzdového mazání.
 *
 * Zakládá dvě izolované firmy (aby šlo ověřit, že cizí tenant nevidí ani
 * `can_delete`), osobu, pracovní vztah a schválenou revizi mzdového běhu —
 * z těch se skládá „doložený pohyb" u většiny entit.
 */
trait PayrollDeletionFixturesTrait
{
    use IsolatedSupplierTrait;

    protected Connection $db;
    protected int $supplierId = 0;
    protected int $otherSupplierId = 0;
    protected int $userId = 0;
    protected int $employeeId = 0;
    protected int $employmentId = 0;

    protected function bootPayrollFixtures(): ContainerInterface
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = Bootstrap::buildApp()->getContainer();
        if ($container === null) {
            self::markTestSkipped('DI kontejner není k dispozici.');
        }
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        if (!$this->db->hasTable('payroll_business_trips')) {
            self::markTestSkipped('Mzdové migrace neproběhly.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = $this->firstId($pdo, 'SELECT id FROM supplier ORDER BY id LIMIT 1');
        $this->userId = $this->firstId($pdo, 'SELECT id FROM users ORDER BY id LIMIT 1');
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);

        $this->employeeId = $this->insertEmployee($this->supplierId, 'Testovací Pracovník');
        $this->employmentId = $this->insertEmployment(
            $this->supplierId,
            $this->employeeId,
            'HPP-DEL',
        );

        return $container;
    }

    private function firstId(PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);

        return $statement === false ? 0 : (int) ($statement->fetchColumn() ?: 0);
    }

    protected function shutdownPayrollFixtures(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    protected function insertEmployee(int $supplierId, string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, 0, 0, 0, NULL, 0, 1)'
        );
        $stmt->execute([$supplierId, $name, 'employee', 'hpp']);

        return (int) $this->db->pdo()->lastInsertId();
    }

    protected function insertEmployment(int $supplierId, int $employeeId, string $code): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, monthly_gross_minor)
             VALUES (?, ?, ?, 'employment', 'active', '2026-01-01', 4000000)"
        );
        $stmt->execute([$supplierId, $employeeId, $code]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Mzdový běh za období — sám o sobě stačí jako důkaz „počítalo se". */
    protected function insertRun(int $supplierId, string $periodStart): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $periodStart,
            (new \DateTimeImmutable($periodStart))->modify('+40 days')->format('Y-m-d'),
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Schválená revize běhu — „schválený výpočet" v podobě, kterou uznávají triggery. */
    protected function insertApprovedRevision(int $supplierId, int $runId, string $seed): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, 'approved', 'v1', ?, '{}', ?, ?, UNHEX(SHA2(?, 256)))"
        );
        $stmt->execute([
            $supplierId,
            $runId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            $seed,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    protected function linkEmploymentToRevision(
        int $supplierId,
        int $revisionId,
        int $employeeId,
        int $employmentId,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_employments
                (supplier_id, revision_id, employee_id, employment_id, input_json, input_hash)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $employmentId,
            '{}',
            str_repeat('d', 64),
        ]);
    }

    protected function rowCount(string $table, string $column, int $value, ?int $supplierId = null): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$column} = ?"
        );
        $stmt->execute([$supplierId ?? $this->supplierId, $value]);

        return (int) $stmt->fetchColumn();
    }

    protected function auditCount(string $action, int $entityId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM activity_log
              WHERE supplier_id = ? AND action = ? AND entity_id = ?'
        );
        $stmt->execute([$this->supplierId, $action, $entityId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    protected function auditPayloadOf(string $action, int $entityId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT payload FROM activity_log
              WHERE supplier_id = ? AND action = ? AND entity_id = ?
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$this->supplierId, $action, $entityId]);
        $payload = $stmt->fetchColumn();
        self::assertIsString($payload, "Auditní záznam {$action} nevznikl.");
        $decoded = json_decode($payload, true);
        self::assertIsArray($decoded);

        $result = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * `role` je záměrně parametr: `accountant` NEMÁ `payroll.settings` ani
     * `payroll.approve` (viz PermissionCatalog::legacyPreset), takže testy
     * nastavení a zrušení schválené cesty musí běžet pod superadminem —
     * a testy vstupů a docházky naopak pod účetní, aby bylo vidět, že jim
     * mazání vlastního překlepu zůstává dostupné.
     *
     * @param array<string,mixed>|null $body
     */
    protected function request(
        string $method,
        string $path,
        ?array $body = null,
        ?int $supplierId = null,
        string $role = 'accountant',
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $supplierId ?? $this->supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => $role],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');

        return $body === null ? $request : $request->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    protected function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        $result = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** @return array<string,mixed> */
    protected function errorOf(ResponseInterface $response): array
    {
        $payload = $this->json($response)['error'] ?? null;
        self::assertIsArray($payload);

        $result = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** Hláška musí vysvětlovat, ne odhalovat vnitřek databáze. */
    protected function assertActionableMessage(string $message): void
    {
        self::assertNotSame('', trim($message));
        self::assertStringNotContainsStringIgnoringCase('constraint', $message);
        self::assertStringNotContainsStringIgnoringCase('sqlstate', $message);
        self::assertStringNotContainsStringIgnoringCase('foreign key', $message);
    }

    /** @return array<string,mixed> */
    protected function fetchRow(string $sql, mixed ...$parameters): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($parameters);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        $result = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
