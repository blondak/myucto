<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollQuickInputsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollQuickInputsApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollQuickInputsAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;
    private int $employmentId;
    private int $otherEmploymentId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(PayrollQuickInputsAction::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = $this->firstId('supplier');
        $this->userId = $this->firstId('users');
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
        $this->employmentId = $this->employment($this->supplierId, 'Syntetická osoba', 'SYN-RYCHLE');
        $this->otherEmploymentId = $this->employment(
            $this->otherSupplierId,
            'Cizí osoba',
            'CIZI-RYCHLE',
        );
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

    public function testListsMaskedMonthlyRowsAndAtomicallyUpsertsDrafts(): void
    {
        $before = $this->action->list(
            $this->request('GET')->withQueryParams(['period' => '2026-06']),
            new Response(),
        );
        self::assertSame(200, $before->getStatusCode(), (string) $before->getBody());
        $month = PayrollTimeValue::row($this->json($before)['month'] ?? null, 'month');
        $rows = $month['items'] ?? null;
        self::assertIsArray($rows);
        self::assertCount(1, $rows);
        self::assertSame(4_200_000, $rows[0]['base_amount_minor']);
        self::assertArrayHasKey('birth_number_masked', $rows[0]);
        self::assertArrayNotHasKey('birth_number', $rows[0]);
        self::assertNull($rows[0]['overtime_hourly_rate_minor']);
        self::assertFalse($rows[0]['overtime_hours_available']);

        $saved = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->employmentId,
                    'base_amount_minor' => 4_200_000,
                    'overtime_mode' => 'amount',
                    'overtime_hours_milli' => null,
                    'overtime_amount_minor' => 35_000,
                    'bonus_amount_minor' => 80_000,
                    'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
                ]],
            ]),
            new Response(),
        );
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $savedRows = PayrollTimeValue::row($this->json($saved)['month'] ?? null, 'month')['items'];
        self::assertIsArray($savedRows);
        self::assertSame(4_315_000, $savedRows[0]['gross_preview_minor']);
        self::assertSame(1, $savedRows[0]['inputs']['base']['row_version']);

        $replay = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->employmentId,
                    'base_amount_minor' => 4_200_000,
                    'overtime_mode' => 'amount',
                    'overtime_hours_milli' => null,
                    'overtime_amount_minor' => 35_000,
                    'bonus_amount_minor' => 80_000,
                    'versions' => ['base' => 1, 'overtime' => 1, 'bonus' => 1],
                ]],
            ]),
            new Response(),
        );
        self::assertSame(200, $replay->getStatusCode(), (string) $replay->getBody());

        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ? AND period_start = "2026-06-01"'
        );
        $stmt->execute([$this->supplierId]);
        self::assertSame(3, (int) $stmt->fetchColumn());
    }

    public function testRejectsHoursWithoutApprovedAverageAndNeverTouchesForeignOrApprovedInput(): void
    {
        $hours = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->employmentId,
                    'base_amount_minor' => 4_200_000,
                    'overtime_mode' => 'hours',
                    'overtime_hours_milli' => 2_000,
                    'overtime_amount_minor' => null,
                    'bonus_amount_minor' => 0,
                    'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
                ]],
            ]),
            new Response(),
        );
        self::assertSame(422, $hours->getStatusCode());

        $foreign = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->otherEmploymentId,
                    'base_amount_minor' => 1,
                    'overtime_mode' => 'amount',
                    'overtime_hours_milli' => null,
                    'overtime_amount_minor' => 0,
                    'bonus_amount_minor' => 0,
                    'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
                ]],
            ]),
            new Response(),
        );
        self::assertSame(422, $foreign->getStatusCode());

        $initial = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->employmentId,
                    'base_amount_minor' => 4_200_000,
                    'overtime_mode' => 'amount',
                    'overtime_hours_milli' => null,
                    'overtime_amount_minor' => 0,
                    'bonus_amount_minor' => 10_000,
                    'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
                ]],
            ]),
            new Response(),
        );
        self::assertSame(200, $initial->getStatusCode());
        $this->db->pdo()->prepare(
            'UPDATE payroll_inputs
                SET status = "approved", component_snapshot_json = "{}",
                    component_snapshot_hash = UNHEX(SHA2("{}", 256))
              WHERE supplier_id = ? AND employment_id = ? AND period_start = "2026-06-01"
                AND external_id = "quick-monthly:ODMENA"'
        )->execute([$this->supplierId, $this->employmentId]);
        $approved = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->employmentId,
                    'base_amount_minor' => 4_200_000,
                    'overtime_mode' => 'amount',
                    'overtime_hours_milli' => null,
                    'overtime_amount_minor' => 0,
                    'bonus_amount_minor' => 20_000,
                    'versions' => ['base' => 1, 'overtime' => 1, 'bonus' => 1],
                ]],
            ]),
            new Response(),
        );
        self::assertSame(409, $approved->getStatusCode());
    }

    public function testCalculatesHoursOnlyFromApprovedAverage(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_average_earning_snapshots
                (supplier_id, employment_id, applicable_year, applicable_quarter,
                 revision_no, source_kind, decisive_from, decisive_to,
                 gross_earnings_minor, longer_period_allocated_minor,
                 worked_minutes, worked_days, average_hourly_minor,
                 support_status, status, ruleset_id, ruleset_hash,
                 input_hash, input_trace)
             VALUES (?, ?, 2026, 2, 1, "actual", "2026-01-01", "2026-03-31",
                     1000000, 0, 6000, 21, 20000,
                     "supported", "approved", "synthetic-2026",
                     REPEAT("a", 64), UNHEX(SHA2("synthetic", 256)), "{}")'
        )->execute([$this->supplierId, $this->employmentId]);
        $averageSnapshotId = (int) $this->db->pdo()->lastInsertId();

        $saved = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->employmentId,
                    'base_amount_minor' => 4_200_000,
                    'overtime_mode' => 'hours',
                    'overtime_hours_milli' => 2_000,
                    'overtime_amount_minor' => null,
                    'overtime_average_snapshot_id' => $averageSnapshotId,
                    'overtime_average_snapshot_version' => 1,
                    'bonus_amount_minor' => 0,
                    'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
                ]],
            ]),
            new Response(),
        );

        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $items = PayrollTimeValue::row($this->json($saved)['month'] ?? null, 'month')['items'];
        self::assertIsArray($items);
        self::assertTrue($items[0]['overtime_hours_available']);
        self::assertSame(2_000, $items[0]['overtime_hours_milli']);
        self::assertSame(50_000, $items[0]['overtime_amount_minor']);
        self::assertSame($averageSnapshotId, $items[0]['overtime_average_snapshot_id']);
        self::assertSame(1, $items[0]['overtime_average_snapshot_version']);

        $trace = $this->db->pdo()->prepare(
            'SELECT source_snapshot_json
               FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ?
                AND period_start = "2026-06-01"
                AND external_id = "quick-monthly:PREMIE_PRIPLATKY"'
        );
        $trace->execute([$this->supplierId, $this->employmentId]);
        $snapshot = json_decode((string) $trace->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($averageSnapshotId, $snapshot['average_snapshot_id']);
        self::assertSame(20_000, $snapshot['average_hourly_minor']);
        self::assertSame(2_000, $snapshot['overtime_hours_milli']);
    }

    public function testEffectiveRecurringBaseIsManagedElsewhereBeforeMaterialization(): void
    {
        $this->action->list(
            $this->request('GET')->withQueryParams(['period' => '2026-06']),
            new Response(),
        );
        $component = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = "MZDA_MESICNI"'
        );
        $component->execute([$this->supplierId]);
        $componentId = (int) $component->fetchColumn();
        self::assertGreaterThan(0, $componentId);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_recurring_components
                (supplier_id, employment_id, component_id, calculation_kind,
                 amount_minor, valid_from, allocation_rule, is_active)
             VALUES (?, ?, ?, "fixed_amount", 4100000, "2026-01-01", "full_month", 1)'
        )->execute([$this->supplierId, $this->employmentId, $componentId]);

        $response = $this->action->list(
            $this->request('GET')->withQueryParams(['period' => '2026-06']),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $item = PayrollTimeValue::row(
            PayrollTimeValue::row($this->json($response)['month'] ?? null, 'month')['items'][0],
            'item',
        );
        self::assertTrue($item['base_managed_elsewhere']);
        self::assertSame(4_100_000, $item['base_amount_minor']);
        self::assertNotEmpty($item['blockers']);

        $saved = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->employmentId,
                    'base_amount_minor' => 4_200_000,
                    'overtime_mode' => 'amount',
                    'overtime_hours_milli' => null,
                    'overtime_amount_minor' => 0,
                    'bonus_amount_minor' => 0,
                    'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
                ]],
            ]),
            new Response(),
        );
        self::assertSame(409, $saved->getStatusCode());
    }

    public function testPartialMonthRequiresExplicitBaseInsteadOfPrefillingFullMonthlyWage(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-06-15", actual_start_date = "2026-06-15"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);

        $response = $this->action->list(
            $this->request('GET')->withQueryParams(['period' => '2026-06']),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $item = PayrollTimeValue::row(
            PayrollTimeValue::row($this->json($response)['month'] ?? null, 'month')['items'][0],
            'item',
        );
        self::assertTrue($item['partial_month']);
        self::assertTrue($item['base_requires_entry']);
        self::assertSame(0, $item['base_amount_minor']);
        self::assertNotEmpty($item['blockers']);
    }

    private function employment(int $supplierId, string $name, string $code): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active",
                     "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$supplierId, $employeeId, $code]);
        return (int) $pdo->lastInsertId();
    }

    private function request(string $method): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/payroll/quick-inputs')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    private function firstId(string $table): int
    {
        if (!in_array($table, ['supplier', 'users'], true)) {
            throw new \InvalidArgumentException('Nepodporovaná tabulka.');
        }
        $stmt = $this->db->pdo()->query("SELECT id FROM {$table} ORDER BY id LIMIT 1");
        if ($stmt === false) {
            throw new \RuntimeException("Tabulku {$table} nelze načíst.");
        }
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        return PayrollTimeValue::row($body, 'response');
    }
}
