<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentAgendaSummaryAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmploymentAgendaSummaryRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Rozcestník navazujících agend na kartě zaměstnance.
 *
 * Hlídá tři věci, kvůli kterým endpoint vznikl: že souhrn opravdu počítá, že
 * NEPŘETEČE do jiné firmy, a že agendu bez oprávnění ze seznamu vypustí místo
 * toho, aby o ní vrátil nulu.
 */
#[Group('integration')]
final class PayrollEmploymentAgendaSummaryApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEmploymentAgendaSummaryAction $action;
    private int $userId;
    private int $supplierId;
    private int $otherSupplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $connection = $container->get(Connection::class);
        $action = $container->get(PayrollEmploymentAgendaSummaryAction::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollEmploymentAgendaSummaryAction::class, $action);
        $this->db = $connection;
        $this->action = $action;

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        self::assertGreaterThan(0, $this->userId);

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    /**
     * `AGENDA_KEYS` je doména pro klientský union, `AGENDAS` nese SQL. Kdyby se
     * rozešly, architekturní kontrakt unionů by zelenal nad seznamem, který
     * endpoint neumí spočítat — proto se porovnávají i na pořadí.
     */
    public function testAgendaCatalogMatchesItsDomain(): void
    {
        self::assertSame(
            PayrollEmploymentAgendaSummaryRepository::AGENDA_KEYS,
            PayrollEmploymentAgendaSummaryRepository::agendaKeys(),
        );
    }

    public function testEmptyEmploymentReportsEveryAgendaAsZero(): void
    {
        [$employmentId] = $this->createEmployment($this->supplierId, 'agenda-empty');

        $agendas = $this->agendas($this->request($this->supplierId), $employmentId);
        self::assertNotSame([], $agendas);
        foreach ($agendas as $key => $row) {
            self::assertSame(0, $row['count'], $key);
            self::assertNull($row['last_on'], $key);
            self::assertNull($row['amount_minor'], $key);
        }
    }

    public function testCountsRecordsOfEmploymentAndPersonScopedAgendas(): void
    {
        [$employmentId, $employeeId] = $this->createEmployment($this->supplierId, 'agenda-filled');
        $this->addAbsence($this->supplierId, $employmentId, '2026-08-10', '2026-08-14');
        $this->addAbsence($this->supplierId, $employmentId, '2026-08-20', '2026-08-21');
        // Zrušená nepřítomnost se nepočítá — není to nic, co by na vztahu „viselo".
        $this->addAbsence($this->supplierId, $employmentId, '2026-09-01', '2026-09-02', 'cancelled');
        $this->addDeductionAgreement($this->supplierId, $employeeId, 250_00, '2026-07-01');
        $this->addDeductionAgreement($this->supplierId, $employeeId, 150_00, '2026-08-01');

        $agendas = $this->agendas($this->request($this->supplierId), $employmentId);

        self::assertSame(2, $agendas['absences']['count']);
        self::assertSame('2026-08-21', $agendas['absences']['last_on']);
        self::assertSame(2, $agendas['deduction_agreements']['count']);
        self::assertSame('2026-08-01', $agendas['deduction_agreements']['last_on']);
        self::assertSame(400_00, $agendas['deduction_agreements']['amount_minor']);
        self::assertSame(0, $agendas['travel']['count']);
        self::assertNull($agendas['travel']['amount_minor']);
    }

    public function testForeignSupplierNeitherSeesTheEmploymentNorItsCounts(): void
    {
        [$employmentId] = $this->createEmployment($this->supplierId, 'agenda-tenant');
        $this->addAbsence($this->supplierId, $employmentId, '2026-08-10', '2026-08-14');

        $response = $this->action->show(
            $this->request($this->otherSupplierId),
            new Response(),
            ['id' => (string) $employmentId],
        );
        self::assertSame(404, $response->getStatusCode());
    }

    public function testAgendaWithoutPermissionIsOmittedInsteadOfReportedAsZero(): void
    {
        [$employmentId, $employeeId] = $this->createEmployment($this->supplierId, 'agenda-perms');
        $this->addEnforcementCase($this->supplierId, $employeeId);

        $admin = $this->agendas($this->request($this->supplierId), $employmentId);
        self::assertArrayHasKey('enforcement', $admin);
        self::assertSame(1, $admin['enforcement']['count']);

        // Účetní roli chybí `payroll.enforcement` (PermissionCatalog::legacyPreset),
        // takže o exekucích nesmí dostat ani počet.
        $accountant = $this->agendas(
            $this->request($this->supplierId, 'accountant'),
            $employmentId,
        );
        self::assertArrayNotHasKey('enforcement', $accountant);
        self::assertArrayHasKey('absences', $accountant);
    }

    /**
     * @return array{0:int,1:int} pořadí: id vztahu, id osoby
     */
    private function createEmployment(int $supplierId, string $code): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická osoba agend", "employee", 1)'
        )->execute([$supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01")'
        )->execute([$supplierId, $employeeId, $code]);

        return [(int) $pdo->lastInsertId(), $employeeId];
    }

    private function addAbsence(
        int $supplierId,
        int $employmentId,
        string $from,
        string $to,
        string $status = 'approved',
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_absences
                (supplier_id, employment_id, absence_type, date_from, date_to,
                 timezone_name, support_status, status)
             VALUES (?, ?, "vacation", ?, ?, "Europe/Prague", "manual_review", ?)'
        )->execute([$supplierId, $employmentId, $from, $to, $status]);
    }

    private function addDeductionAgreement(
        int $supplierId,
        int $employeeId,
        int $requestedMinor,
        string $validFrom,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_deduction_agreements
                (supplier_id, employee_id, agreement_reference, title, deduction_kind,
                 status, priority_no, requested_minor, valid_from)
             VALUES (?, ?, ?, "Syntetická srážka", "meal", "active", 100, ?, ?)'
        )->execute([
            $supplierId,
            $employeeId,
            'SYNTH-' . $employeeId . '-' . $validFrom,
            $requestedMinor,
            $validFrom,
        ]);
    }

    private function addEnforcementCase(int $supplierId, int $employeeId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_cases
                (supplier_id, employee_id, case_key, case_kind, status, effective_from)
             VALUES (?, ?, ?, "enforcement", "received", "2026-06-01")'
        )->execute([$supplierId, $employeeId, 'SYNTH-EXE-' . $employeeId]);
    }

    /**
     * Souhrn agend klíčovaný podle agendy — testy se ptají na konkrétní agendu,
     * ne na pořadí v seznamu.
     *
     * @return array<string,array{count:int,last_on:string|null,amount_minor:int|null}>
     */
    private function agendas(ServerRequestInterface $request, int $employmentId): array
    {
        $response = $this->action->show($request, new Response(), ['id' => (string) $employmentId]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        $summary = $decoded['summary'] ?? null;
        self::assertIsArray($summary);
        self::assertSame($employmentId, $summary['employment_id']);
        $rows = $summary['agendas'] ?? null;
        self::assertIsArray($rows);

        $byKey = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $byKey[(string) $row['key']] = [
                'count' => (int) $row['count'],
                'last_on' => $row['last_on'] === null ? null : (string) $row['last_on'],
                'amount_minor' => $row['amount_minor'] === null ? null : (int) $row['amount_minor'],
            ];
        }

        return $byKey;
    }

    private function request(int $supplierId, string $role = 'admin'): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/employments/1/agenda-summary')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $this->userId,
                'role' => $role,
            ])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }
}
