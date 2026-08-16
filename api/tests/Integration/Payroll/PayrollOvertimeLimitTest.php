<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Service\Payroll\Run\PayrollRunCommand;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunStatus;
use MyInvoice\Service\Payroll\Run\PayrollRunTransitionContext;
use MyInvoice\Service\Payroll\Run\PayrollRunValidation;
use MyInvoice\Service\Payroll\Run\PayrollRunWorkflow;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeLimitFinding;
use MyInvoice\Service\Payroll\Time\PayrollTimeService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Limity přesčasu podle § 93 zákoníku práce na cestě z docházky do mzdového běhu.
 *
 * Těžiště téhle sady je v tom, co kontrola NESMÍ udělat: zastavit výplatu.
 * Přesčas, který zaměstnanec odpracoval, se podle § 114 platí i tehdy, když ho
 * zaměstnavatel nařídil nad zákonný rozsah — nález je vada zaměstnavatele, ne
 * důvod nevyplatit mzdu.
 */
#[Group('integration')]
final class PayrollOvertimeLimitTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRunSnapshotBuilder $builder;
    private PayrollTimeService $time;
    private int $supplierId;
    private int $employmentId;
    private int $actorId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->builder = $container->get(PayrollRunSnapshotBuilder::class);
        $this->time = $container->get(PayrollTimeService::class);
        foreach (['payroll_overtime_consents', 'payroll_time_entries', 'payroll_runs'] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Migrace pro přesčasové limity neproběhly.');
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->actorId = $this->createActor();
        $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())'
        )->execute([$this->supplierId, $this->actorId]);
        $container->get(PayrollEmployerPolicyRepository::class)->create(
            $this->supplierId,
            [
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'payday_day' => 10,
                'payday_month_offset' => 1,
                'payday_business_day_rule' => 'previous_business_day',
                'balance_rounding_mode' => 'exact_minor_units',
                'home_office_policy' => 'not_used',
                'travel_expense_policy' => 'not_used',
                'four_eyes_required' => true,
                'automatic_calculation_enabled' => true,
                'automatic_posting_enabled' => true,
                'automatic_payments_enabled' => true,
                'delivery_channel' => 'disabled',
                'delivery_verified_on' => null,
                'source_kind' => 'manual',
                'source_reference' => 'synthetic:overtime-limits',
            ],
            $this->actorId,
        );
        $this->employmentId = $this->employment();
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

    public function testWeeklyOvertimeOverLimitReachesTheRunAsWarningOnly(): void
    {
        // Pondělí až středa po třech hodinách přesčasu = 9 h v jednom týdnu.
        $this->overtime('2026-05-04', 180);
        $this->overtime('2026-05-05', 180);
        $this->overtime('2026-05-06', 180);

        $findings = $this->overtimeValidations($this->build());
        self::assertNotSame([], $findings);
        $weekly = $this->of($findings, OvertimeLimitFinding::CODE_WEEKLY);
        self::assertCount(1, $weekly);
        self::assertSame('warning', $weekly[0]->severity);
        self::assertFalse($weekly[0]->requiresOverride);
        self::assertSame('employment', $weekly[0]->entityType);
        self::assertSame($this->employmentId, $weekly[0]->entityId);
        self::assertSame('/payroll/time', $weekly[0]->remediationPath);
        self::assertStringContainsString('§ 93 odst. 2', $weekly[0]->message);
    }

    /**
     * Nález nesmí být `blocker` ani varování s `requires_override`. Obojí
     * {@see PayrollRunWorkflow} na příkazu `approve` zastaví, a `requires_override`
     * navíc nemá jak odblokovat — sloupce `override_reason` / `overridden_at`
     * v `payroll_run_validations` od migrace 1210 nikdo nenastavuje.
     */
    public function testApprovalIsNotBlockedByOvertimeWarnings(): void
    {
        $this->overtime('2026-05-04', 600);
        $this->overtime('2026-05-05', 600);
        $findings = $this->overtimeValidations($this->build());
        self::assertNotSame([], $findings);

        $blockers = 0;
        $unresolvedOverrides = 0;
        foreach ($findings as $finding) {
            if ($finding->severity === 'blocker') {
                ++$blockers;
            }
            if ($finding->severity === 'warning' && $finding->requiresOverride) {
                ++$unresolvedOverrides;
            }
        }
        self::assertSame(0, $blockers);
        self::assertSame(0, $unresolvedOverrides);

        $transition = (new PayrollRunWorkflow())->transition(
            PayrollRunStatus::REVIEWED,
            PayrollRunCommand::APPROVE,
            new PayrollRunTransitionContext(
                actorUserId: $this->actorId,
                calculatedBy: $this->actorId + 1,
                reviewedBy: $this->actorId + 2,
                blockerCount: $blockers,
                unresolvedOverrideCount: $unresolvedOverrides,
                hasImmutableSnapshot: true,
                hasCalculatedResult: true,
            ),
        );
        self::assertSame(PayrollRunStatus::APPROVED, $transition->to);
    }

    /**
     * Historická revize se musí přepočítat na bit stejně. Nálezy proto leží
     * VEDLE kanonického snapshotu — přibývající přesčas v docházce jeho otisk
     * nesmí pohnout, jinak by kontrola měnila to, co se počítá a vyplácí.
     */
    public function testOvertimeFindingsDoNotChangeTheCanonicalInputSnapshot(): void
    {
        $this->overtime('2026-05-04', 180);
        $first = $this->build();
        $hash = $first->hash;

        $this->overtime('2026-05-05', 600);
        $this->overtime('2026-05-06', 600);
        $second = $this->build();

        self::assertSame($hash, $second->hash);
        self::assertSame($first->json, $second->json);
        self::assertStringNotContainsString('overtime_', $second->json);
        self::assertGreaterThan(
            count($this->overtimeValidations($first)),
            count($this->overtimeValidations($second)),
        );
    }

    /**
     * § 93 odst. 3 — s evidovanou dohodou přestává být přesčas nařízený, takže
     * se limity odst. 2 na něj nevztahují a varování zmizí.
     */
    public function testEvidencedConsentSilencesTheOrderedLimitFinding(): void
    {
        $this->overtime('2026-05-04', 300);
        $this->overtime('2026-05-05', 300);
        self::assertNotSame([], $this->overtimeValidations($this->build()));

        $this->time->saveOvertimeConsent(
            $this->supplierId,
            [
                'employment_id' => $this->employmentId,
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'document_reference' => 'DOHODA/2026/1',
                'note' => 'Syntetická dohoda o práci přesčas.',
                'row_version' => 0,
            ],
            $this->actorId,
        );

        self::assertSame([], $this->overtimeValidations($this->build()));
    }

    public function testAttendanceOverviewExposesTheLimitState(): void
    {
        $this->overtime('2026-05-04', 300);
        $this->overtime('2026-05-05', 300);

        $items = $this->time->overview($this->supplierId, '2026-05', false)['items'];
        self::assertCount(1, $items);
        $limits = $items[0]['overtime_limits'];
        self::assertIsArray($limits);
        self::assertSame(600, $limits['ordered_year_minutes']);
        self::assertSame(9_000, $limits['ordered_year_limit_minutes']);
        self::assertFalse($limits['consent_evidenced']);
        self::assertSame(
            [OvertimeLimitFinding::CODE_WEEKLY],
            array_column($limits['findings'], 'code'),
        );
        self::assertSame([], $items[0]['overtime_consents']);
    }

    public function testSupersededRevisionOfATimeEntryIsNotCountedTwice(): void
    {
        $this->overtime('2026-05-04', 600, 'superseded');
        $this->overtime('2026-05-04', 300);

        $items = $this->time->overview($this->supplierId, '2026-05', false)['items'];
        self::assertSame(300, $items[0]['overtime_limits']['ordered_year_minutes']);
    }

    private function build(): \MyInvoice\Service\Payroll\Run\PayrollRunInputSnapshot
    {
        return $this->builder->build($this->supplierId, '2026-05-01', '2026-06-10');
    }

    /** @return list<PayrollRunValidation> */
    private function overtimeValidations(
        \MyInvoice\Service\Payroll\Run\PayrollRunInputSnapshot $snapshot,
    ): array {
        return array_values(array_filter(
            $snapshot->validations,
            static fn (PayrollRunValidation $validation): bool =>
                str_starts_with($validation->code, 'overtime_'),
        ));
    }

    /**
     * @param list<PayrollRunValidation> $validations
     * @return list<PayrollRunValidation>
     */
    private function of(array $validations, string $code): array
    {
        return array_values(array_filter(
            $validations,
            static fn (PayrollRunValidation $validation): bool => $validation->code === $code,
        ));
    }

    private function overtime(string $date, int $minutes, string $status = 'approved'): void
    {
        $start = new \DateTimeImmutable(
            $date . ' 16:00:00',
            new \DateTimeZone('Europe/Prague'),
        );
        $end = $start->modify("+{$minutes} minutes");
        $utc = new \DateTimeZone('UTC');
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_entries
                (supplier_id, employment_id, series_key, revision_no, category,
                 starts_at_utc, ends_at_utc, timezone_name, break_minutes,
                 source_kind, source_hash, status, approved_at)
             VALUES (?, ?, ?, 1, "overtime", ?, ?, "Europe/Prague", 0,
                     "manual", ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            bin2hex(random_bytes(16)),
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
            random_bytes(32),
            $status,
            $status === 'approved' ? $start->format('Y-m-d H:i:s') : null,
        ]);
    }

    private function employment(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetický přesčasář", "employee", 1)'
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
             VALUES (?, ?, "SYN-OT", "employment", "active",
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

        return $employmentId;
    }

    private function createActor(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetická mzdová účetní", "readonly", "cs", 1)'
        );
        $stmt->execute([
            'overtime-' . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }
}
