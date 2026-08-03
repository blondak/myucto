<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRunConflictException;
use MyInvoice\Repository\Payroll\PayrollRunIdempotencyException;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollRunPersistenceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRunCommandService $service;
    private PayrollRunRepository $runs;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $employmentId;
    private int $inputId;
    /** @var list<int> */
    private array $actors;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $service = $container->get(PayrollRunCommandService::class);
        $runs = $container->get(PayrollRunRepository::class);
        if (!$db instanceof Connection
            || !$service instanceof PayrollRunCommandService
            || !$runs instanceof PayrollRunRepository
        ) {
            throw new \RuntimeException('Služby mzdového běhu nejsou dostupné.');
        }
        $this->db = $db;
        $this->service = $service;
        $this->runs = $runs;
        foreach ([
            'payroll_runs',
            'payroll_run_revisions',
            'payroll_run_commands',
            'payroll_run_events',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Migrace MZ-09 neproběhly.');
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)'
        )->execute([$this->supplierId, $this->otherSupplierId]);
        $this->actors = [
            $this->createActor('calculator'),
            $this->createActor('reviewer'),
            $this->createActor('approver'),
        ];
        foreach ([$this->supplierId, $this->otherSupplierId] as $supplierId) {
            $pdo->prepare(
                'INSERT INTO payroll_module_state
                    (supplier_id, status, start_period, activated_by, activated_at)
                 VALUES (?, "active", "2026-01-01", ?, NOW())'
            )->execute([$supplierId, $this->actors[0]]);
        }
        [$this->employeeId, $this->employmentId] = $this->employment();
        $this->inputId = $this->approvedInput(120_000, 'BASE', 'manual');
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

    public function testSnapshotRemainsStableAndFourEyeWorkflowIsAudited(): void
    {
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-stable-snapshot',
            $this->actors[0],
        );
        self::assertSame('inputs_locked', $locked->run['status']);
        self::assertSame('snapshot', $locked->revision['status']);
        self::assertSame(
            'locked',
            $this->scalar(
                'SELECT status FROM payroll_inputs WHERE supplier_id = ? AND id = ?',
                [$this->supplierId, $this->inputId],
            ),
        );
        $inputHash = $locked->revision['input_snapshot_hash'];

        $this->db->pdo()->prepare(
            'UPDATE payroll_employees SET full_name = "Changed after lock"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employeeId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_inputs SET amount_minor = 999999
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->inputId]);

        $calculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-stable-snapshot',
            $this->actors[0],
        );
        self::assertSame('calculated', $calculated->run['status']);
        self::assertSame(
            120_000,
            $calculated->revision['result_snapshot']['totals']['source_amount_minor'],
        );
        self::assertSame($inputHash, $calculated->revision['input_snapshot_hash']);

        $reviewed = $this->service->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'review-four-eyes',
            $this->actors[1],
        );
        $approved = $this->service->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'approve-four-eyes',
            $this->actors[2],
        );
        self::assertSame('approved', $approved->run['status']);
        self::assertSame($this->actors[0], $approved->revision['calculated_by']);
        self::assertSame($this->actors[1], $approved->revision['reviewed_by']);
        self::assertSame($this->actors[2], $approved->revision['approved_by']);

        $events = $this->runs->events($this->supplierId, (int) $run['id']);
        self::assertSame(
            ['created', 'lock_inputs', 'calculate', 'review', 'approve'],
            array_column($events, 'event_type'),
        );
        self::assertCount(4, array_filter(
            $events,
            static fn (array $event): bool =>
                isset($event['metadata']['idempotency_key_hash']),
        ));
        self::assertStringNotContainsString(
            'approve-four-eyes',
            CanonicalJson::encode(['events' => $events]),
        );

        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_run_revisions SET input_snapshot_hash = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([
            str_repeat('0', 64),
            $this->supplierId,
            (int) $approved->revision['id'],
        ]);
    }

    public function testIdempotentReplayTenantIsolationAndOptimisticConflict(): void
    {
        $run = $this->createRun();
        $sameRun = $this->createRun();
        self::assertSame($run['id'], $sameRun['id']);
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_events
                  WHERE supplier_id = ? AND run_id = ? AND event_type = "created"',
                [$this->supplierId, $run['id']],
            ),
        );
        $first = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'same-command-retry',
            $this->actors[0],
        );
        $replay = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'same-command-retry',
            $this->actors[0],
        );

        self::assertFalse($first->idempotentReplay);
        self::assertTrue($replay->idempotentReplay);
        self::assertSame($first->revision['id'], $replay->revision['id']);
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_revisions
                  WHERE supplier_id = ? AND run_id = ?',
                [$this->supplierId, $run['id']],
            ),
        );
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_commands
                  WHERE supplier_id = ? AND run_id = ?',
                [$this->supplierId, $run['id']],
            ),
        );
        self::assertNull($this->runs->find($this->otherSupplierId, (int) $run['id']));

        try {
            $this->service->calculate(
                $this->otherSupplierId,
                (int) $run['id'],
                (int) $first->run['row_version'],
                'foreign-tenant-command',
                $this->actors[0],
            );
            self::fail('Cizí tenant nesmí ovládat běh.');
        } catch (\OutOfBoundsException) {
            self::addToAssertionCount(1);
        }

        try {
            $this->service->calculate(
                $this->supplierId,
                (int) $run['id'],
                (int) $run['row_version'],
                'stale-row-version',
                $this->actors[0],
            );
            self::fail('Stará row_version musí skončit konfliktem.');
        } catch (PayrollRunConflictException $e) {
            self::assertSame((int) $first->run['row_version'], $e->currentVersion);
        }

        $this->expectException(PayrollRunIdempotencyException::class);
        $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $first->run['row_version'],
            'same-command-retry',
            $this->actors[0],
        );
    }

    public function testCommandsStopWhenPayrollModuleIsDisabled(): void
    {
        $run = $this->createRun();
        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_enabled = 0 WHERE id = ?'
        )->execute([$this->supplierId]);

        try {
            $this->service->lockInputs(
                $this->supplierId,
                (int) $run['id'],
                (int) $run['row_version'],
                'disabled-module-command',
                $this->actors[0],
            );
            self::fail('Vypnutý mzdový modul nesmí přijímat stavové příkazy.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('vedení mezd zapnuté', $e->getMessage());
        }

        self::assertSame(
            'draft',
            $this->runs->find($this->supplierId, (int) $run['id'])['status'],
        );
    }

    public function testSuccessfulCommandCanReplayAfterModuleIsDisabled(): void
    {
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'replay-after-module-disabled',
            $this->actors[0],
        );
        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_enabled = 0 WHERE id = ?'
        )->execute([$this->supplierId]);

        $replayed = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'replay-after-module-disabled',
            $this->actors[0],
        );

        self::assertTrue($replayed->idempotentReplay);
        self::assertSame($locked->revision['id'], $replayed->revision['id']);
    }

    public function testCorrectionCreatesNewRevisionAndPreservesApprovedHistory(): void
    {
        $approved = $this->approveInitialRun();
        $runId = (int) $approved->run['id'];
        $originalRevisionId = (int) $approved->revision['id'];
        $originalHash = (string) $approved->revision['result_snapshot_hash'];

        $this->approvedInput(10_000, 'CORRECTION', 'correction');
        $requested = $this->service->requestCorrection(
            $this->supplierId,
            $runId,
            (int) $approved->run['row_version'],
            'request-correction',
            $this->actors[2],
            'Doplatek syntetické prémie.',
        );
        $reopened = $this->service->reopen(
            $this->supplierId,
            $runId,
            (int) $requested->run['row_version'],
            'reopen-correction',
            $this->actors[1],
            'Doplatek syntetické prémie.',
        );

        self::assertSame(2, $reopened->revision['revision_no']);
        self::assertSame('correction', $reopened->revision['revision_kind']);
        self::assertSame(
            $originalRevisionId,
            $reopened->revision['previous_revision_id'],
        );
        $revisions = $this->runs->revisions($this->supplierId, $runId);
        self::assertCount(2, $revisions);
        self::assertSame('approved', $revisions[0]['status']);
        self::assertSame($originalHash, $revisions[0]['result_snapshot_hash']);

        $calculated = $this->service->calculate(
            $this->supplierId,
            $runId,
            (int) $reopened->run['row_version'],
            'calculate-correction',
            $this->actors[0],
        );
        self::assertSame(
            130_000,
            $calculated->revision['result_snapshot']['totals']['source_amount_minor'],
        );
        $events = $this->runs->events($this->supplierId, $runId);
        $correctionEvent = array_values(array_filter(
            $events,
            static fn (array $event): bool =>
                $event['event_type'] === 'request_correction',
        ))[0];
        self::assertSame('Doplatek syntetické prémie.', $correctionEvent['reason']);
    }

    public function testSnapshotValidationBlocksApprovalWithoutChangingReviewedRun(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_inputs SET status = "draft",
                    component_snapshot_json = NULL,
                    component_snapshot_hash = NULL,
                    approved_by = NULL,
                    approved_at = NULL
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->inputId]);
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-with-blocker',
            $this->actors[0],
        );
        $validations = $this->runs->validations(
            $this->supplierId,
            (int) $locked->revision['id'],
        );
        self::assertContains('draft_inputs_present', array_column($validations, 'code'));
        self::assertContains('employment_without_inputs', array_column($validations, 'code'));

        $calculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-with-blocker',
            $this->actors[0],
        );
        $reviewed = $this->service->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'review-with-blocker',
            $this->actors[1],
        );
        try {
            $this->service->approve(
                $this->supplierId,
                (int) $run['id'],
                (int) $reviewed->run['row_version'],
                'approve-with-blocker',
                $this->actors[2],
            );
            self::fail('Blokující validace nesmí dovolit schválení.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('blokující validace', $e->getMessage());
        }
        self::assertSame(
            'reviewed',
            $this->runs->find($this->supplierId, (int) $run['id'])['status'],
        );
        self::assertSame(
            0,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_commands
                  WHERE supplier_id = ? AND run_id = ? AND command_name = "approve"',
                [$this->supplierId, $run['id']],
            ),
        );
    }

    public function testAuditEventsAreAppendOnlyAtDatabaseBoundary(): void
    {
        $run = $this->createRun();
        $eventId = (int) $this->scalar(
            'SELECT id FROM payroll_run_events
              WHERE supplier_id = ? AND run_id = ? AND event_type = "created"',
            [$this->supplierId, $run['id']],
        );
        self::assertGreaterThan(0, $eventId);

        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_run_events SET reason = "tamper"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $eventId]);
    }

    private function createRun(): array
    {
        return $this->service->createRun(
            $this->supplierId,
            '2026-06-01',
            null,
            $this->actors[0],
        );
    }

    private function approveInitialRun(): \MyInvoice\Service\Payroll\Run\PayrollRunCommandResult
    {
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-for-correction',
            $this->actors[0],
        );
        $calculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-for-correction',
            $this->actors[0],
        );
        $reviewed = $this->service->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'review-for-correction',
            $this->actors[1],
        );
        return $this->service->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'approve-for-correction',
            $this->actors[2],
        );
    }

    private function createActor(string $suffix): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, ?, "readonly", "cs", 1)'
        );
        $stmt->execute([
            "mz09-{$suffix}-" . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash000000000000000000',
            "Synthetic {$suffix}",
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{int,int} */
    private function employment(): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Synthetic Payroll Run Person", "employee", 1)'
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "ready")'
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, is_primary)
             VALUES (?, ?, "SYN-MZ09", "employment", "active",
                     "2026-01-01", "2026-01-01", 1)'
        )->execute([$this->supplierId, $employeeId]);
        $employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, effective_from, planned_start_on,
                 actual_start_on, weekly_hours, workload_basis_points,
                 social_insurance_participation,
                 health_insurance_participation, tax_regime,
                 tax_declaration_signed, is_primary)
             VALUES (?, ?, "2026-01-01", "2026-01-01", "2026-01-01",
                     40, 10000, "automatic", "automatic", "advance", 1, 1)'
        )->execute([$this->supplierId, $employmentId]);
        return [$employeeId, $employmentId];
    }

    private function approvedInput(
        int $amountMinor,
        string $code,
        string $sourceKind,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment, social_treatment,
                 health_treatment, average_earning_treatment,
                 enforcement_treatment, jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code, valid_from)
             VALUES (?, ?, ?, "base_wage", "monetary", "regular", "included",
                     "included", "included", "included", "included", "included",
                     "included", "521", "331", "2026-01-01")'
        )->execute([$this->supplierId, $code, "Synthetic {$code}"]);
        $componentId = (int) $pdo->lastInsertId();
        $snapshot = [
            'code' => $code,
            'name' => "Synthetic {$code}",
            'component_kind' => 'base_wage',
            'value_kind' => 'monetary',
            'frequency_kind' => 'regular',
            'tax_treatment' => 'included',
            'social_treatment' => 'included',
            'health_treatment' => 'included',
            'average_earning_treatment' => 'included',
            'enforcement_treatment' => 'included',
            'jmhz_treatment' => 'included',
            'statistics_treatment' => 'included',
            'accounting_debit_code' => '521',
            'accounting_credit_code' => '331',
            'annual_limit_minor' => null,
            'component_id' => $componentId,
            'component_row_version' => 1,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ];
        $json = CanonicalJson::encode($snapshot);
        $pdo->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id,
                 period_start, amount_minor, source_kind, status,
                 component_snapshot_json, component_snapshot_hash,
                 approved_by, approved_at)
             VALUES (?, ?, ?, ?, "2026-06-01", ?, ?, "approved", ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            $componentId,
            $amountMinor,
            $sourceKind,
            $json,
            hash('sha256', $json, true),
            $this->actors[0],
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
