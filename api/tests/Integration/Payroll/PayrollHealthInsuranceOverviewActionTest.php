<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollHealthInsuranceOverviewAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollHealthInsuranceOverviewActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollHealthInsuranceOverviewAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;
    private int $revisionId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer()
            ?? throw new \RuntimeException('DI kontejner není dostupný.');
        $db = $container->get(Connection::class);
        $action = $container->get(PayrollHealthInsuranceOverviewAction::class);
        if (!$db instanceof Connection
            || !$action instanceof PayrollHealthInsuranceOverviewAction
        ) {
            throw new \RuntimeException(
                'Zdravotní přehled není dostupný v DI kontejneru.',
            );
        }
        $this->db = $db;
        $this->action = $action;
        foreach ([
            'payroll_runs',
            'payroll_run_revisions',
            'payroll_statutory_results',
            'payroll_statutory_person_results',
        ] as $table) {
            if (!$db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }

        $pdo = $db->pdo();
        $sourceSupplierId = $this->firstId($pdo, 'supplier');
        $this->userId = $this->firstId($pdo, 'users');
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->otherSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $pdo->prepare(
            'UPDATE supplier
                SET payroll_enabled = 1
              WHERE id IN (?, ?)',
        )->execute([$this->supplierId, $this->otherSupplierId]);
        $employeeId = $this->employee($pdo);
        $this->revisionId = $this->revision($pdo, $employeeId);
        $this->healthResult($employeeId);
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

    public function testIndexReturnsDeterministicTenantOverview(): void
    {
        $response = $this->action->index(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$this->revisionId}",
            ),
            new Response(),
            ['revisionId' => (string) $this->revisionId],
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertFalse($body['electronic_submission']['supported']);
        self::assertSame(
            'health_insurance_transport_unavailable',
            $body['electronic_submission']['reason_code'],
        );
        self::assertCount(1, $body['items']);
        self::assertSame('111', $body['items'][0]['insurer']['code']);
        self::assertSame(
            'Syntetická HTTP osoba',
            $body['items'][0]['people'][0]['display_name'],
        );
        self::assertSame(
            135_000,
            $body['items'][0]['totals']['total_contribution_minor_units'],
        );
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $body['items'][0]['sha256'],
        );
        self::assertStringNotContainsString(
            'Syntetická',
            $body['items'][0]['filename'],
        );
    }

    public function testDownloadReturnsExactPrivateBytesAndSafeFilename(): void
    {
        $response = $this->action->download(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$this->revisionId}/111/download",
            ),
            new Response(),
            [
                'revisionId' => (string) $this->revisionId,
                'insurerCode' => '111',
            ],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'application/json; charset=utf-8',
            $response->getHeaderLine('Content-Type'),
        );
        self::assertSame(
            'private, no-store',
            $response->getHeaderLine('Cache-Control'),
        );
        self::assertSame('nosniff', $response->getHeaderLine(
            'X-Content-Type-Options',
        ));
        self::assertSame(
            'attachment; filename="zp-prehled-2026-06-111-revize-'
                . $this->revisionId . '.json"',
            $response->getHeaderLine('Content-Disposition'),
        );
        $bytes = (string) $response->getBody();
        self::assertSame((string) strlen($bytes), $response->getHeaderLine(
            'Content-Length',
        ));
        self::assertSame(
            hash('sha256', $bytes),
            $response->getHeaderLine('Content-SHA256'),
        );
        $decoded = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertFalse($decoded['official_submission']['supported']);
        self::assertSame('111', $decoded['insurer']['code']);
    }

    public function testSessionPermissionAndPayrollSwitchAreFailClosed(): void
    {
        $bearer = $this->action->index(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$this->revisionId}",
                null,
                'bearer',
            ),
            new Response(),
            ['revisionId' => (string) $this->revisionId],
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame(
            'session_required',
            $this->json($bearer)['error']['code'],
        );

        $deniedRole = new EffectiveRole(
            901,
            'Bez mzdových podání',
            'staff',
            true,
            ['payroll.submissions' => AccessLevel::NONE->value],
        );
        $forbidden = $this->action->index(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$this->revisionId}",
                null,
                'session',
                $deniedRole,
            ),
            new Response(),
            ['revisionId' => (string) $this->revisionId],
        );
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('forbidden', $this->json($forbidden)['error']['code']);

        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_enabled = 0 WHERE id = ?',
        )->execute([$this->supplierId]);
        $disabled = $this->action->index(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$this->revisionId}",
            ),
            new Response(),
            ['revisionId' => (string) $this->revisionId],
        );
        self::assertSame(403, $disabled->getStatusCode());
        self::assertSame(
            'payroll_disabled',
            $this->json($disabled)['error']['code'],
        );
    }

    public function testTenantMissingAndInvalidRouteValuesDoNotLeakData(): void
    {
        $foreign = $this->action->index(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$this->revisionId}",
                $this->otherSupplierId,
            ),
            new Response(),
            ['revisionId' => (string) $this->revisionId],
        );
        self::assertSame(422, $foreign->getStatusCode());
        self::assertSame(
            'health_insurance_result_not_found',
            $this->json($foreign)['error']['code'],
        );

        $invalidRevision = $this->action->index(
            $this->request(
                'GET',
                '/api/payroll/submissions/health-overviews/not-a-number',
            ),
            new Response(),
            ['revisionId' => 'not-a-number'],
        );
        self::assertSame(422, $invalidRevision->getStatusCode());
        self::assertSame(
            'validation_failed',
            $this->json($invalidRevision)['error']['code'],
        );

        $missingInsurer = $this->action->download(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$this->revisionId}/201/download",
            ),
            new Response(),
            [
                'revisionId' => (string) $this->revisionId,
                'insurerCode' => '201',
            ],
        );
        self::assertSame(404, $missingInsurer->getStatusCode());
        self::assertSame(
            'not_found',
            $this->json($missingInsurer)['error']['code'],
        );

        $invalidInsurer = $this->action->download(
            $this->request(
                'GET',
                "/api/payroll/submissions/health-overviews/{$this->revisionId}/11/download",
            ),
            new Response(),
            [
                'revisionId' => (string) $this->revisionId,
                'insurerCode' => '11',
            ],
        );
        self::assertSame(422, $invalidInsurer->getStatusCode());
        self::assertSame(
            'validation_failed',
            $this->json($invalidInsurer)['error']['code'],
        );
    }

    private function employee(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická HTTP osoba", "employee", "hpp",
                     1, 1, 0, 10000, 0, 1)',
        )->execute([$this->supplierId]);

        return (int) $pdo->lastInsertId();
    }

    private function revision(PDO $pdo, int $employeeId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, "2026-06-01", "2026-07-10", "approved", 1)',
        )->execute([$this->supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $input = '{"schema_version":"payroll-run-input.v2"}';
        $result = '{"schema_version":"payroll-run-result.v2"}';
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "regular", "approved",
                     "payroll-run-input.v2", ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $input,
            hash('sha256', $input),
            $result,
            hash('sha256', $result),
            hash(
                'sha256',
                "synthetic-health-http:{$this->supplierId}:{$runId}",
                true,
            ),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status)
             VALUES (?, ?, ?, "calculated")',
        )->execute([$this->supplierId, $revisionId, $employeeId]);

        return $revisionId;
    }

    private function healthResult(int $employeeId): void
    {
        (new PayrollStatutoryResultRepository($this->db))->store(
            $this->supplierId,
            $this->revisionId,
            'health_insurance',
            'payroll-health-result.v1',
            'calculated',
            'cz-health-2026',
            str_repeat('b', 64),
            ['schema_version' => 'payroll-run-input.v2'],
            [
                'calculation_date' => '2026-06-30',
                'status' => 'calculated',
                'assessment_base_minor_units' => 1_000_000,
                'employee_contribution_minor_units' => 45_000,
                'employer_contribution_minor_units' => 90_000,
                'total_contribution_minor_units' => 135_000,
                'insurer_liabilities' => [[
                    'insurer_code' => '111',
                    'person_count' => 1,
                    'assessment_base_minor_units' => 1_000_000,
                    'employee_contribution_minor_units' => 45_000,
                    'employer_contribution_minor_units' => 90_000,
                    'total_contribution_minor_units' => 135_000,
                ]],
                'issues' => [],
                'ruleset_id' => 'cz-health-2026',
                'ruleset_hash' => str_repeat('b', 64),
            ],
            [[
                'employee_id' => $employeeId,
                'result_status' => 'calculated',
                'input_snapshot' => [
                    'employee' => [
                        'id' => $employeeId,
                        'full_name' => 'Syntetická HTTP osoba',
                    ],
                ],
                'result_snapshot' => [
                    'person_id' => "employee:{$employeeId}",
                    'status' => 'calculated',
                    'insurer_status' => 'verified',
                    'insurer_code' => '111',
                    'ppz_counted' => true,
                    'assessment_base_minor_units' => 1_000_000,
                    'employee_contribution_minor_units' => 45_000,
                    'employer_contribution_minor_units' => 90_000,
                    'total_contribution_minor_units' => 135_000,
                ],
                'relationships' => [],
            ]],
            null,
        );
    }

    private function firstId(PDO $pdo, string $table): int
    {
        $statement = $pdo->query(
            "SELECT id FROM {$table} ORDER BY id LIMIT 1",
        );
        if ($statement === false) {
            return 0;
        }

        return (int) $statement->fetchColumn();
    }

    private function request(
        string $method,
        string $uri,
        ?int $supplierId = null,
        string $authMethod = 'session',
        ?EffectiveRole $role = null,
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $supplierId ?? $this->supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'accountant'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
        if ($role !== null) {
            $request = $request->withAttribute('auth.effective_role', $role);
        }

        return $request;
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode(
            (string) $response->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);

        return $decoded;
    }
}
