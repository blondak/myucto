<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationException;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Životní cyklus podání přehledu o platbě zdravotní pojišťovně: povinnost →
 * podání → část → artefakt → výhrada nebo připravenost.
 */
#[Group('integration')]
final class PayrollHealthInsuranceSubmissionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private HealthInsuranceSubmissionService $service;
    private HealthInsuranceSchemaCatalog $schemas;
    private PayrollSubmissionService $submissions;
    private int $supplierId;
    private int $revisionId;
    private int $employmentId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        $this->db = $db;
        foreach ([
            'payroll_runs',
            'payroll_run_revisions',
            'payroll_statutory_results',
            'payroll_obligations',
            'payroll_submissions',
            'payroll_submission_artifacts',
            'payroll_person_health_coverage_history',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $service = $container->get(HealthInsuranceSubmissionService::class);
        $submissions = $container->get(PayrollSubmissionService::class);
        if (!$service instanceof HealthInsuranceSubmissionService
            || !$submissions instanceof PayrollSubmissionService
        ) {
            throw new \RuntimeException('Služby podání nejsou dostupné.');
        }
        $this->service = $service;
        $this->submissions = $submissions;
        $this->schemas = new HealthInsuranceSchemaCatalog();

        $pdo = $this->db->pdo();
        $statement = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        if ($statement === false) {
            throw new \RuntimeException('Výchozí firmu nelze načíst.');
        }
        $sourceSupplierId = (int) $statement->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $pdo->prepare(
            'UPDATE supplier
                SET ic = "12345678", company_name = "Syntetický plátce s.r.o.",
                    street = "Zkušební", street_number_pop = "12",
                    zip = "110 00", city = "Praha 1", phone = "+420111222333"
              WHERE id = ?',
        )->execute([$this->supplierId]);
        $employeeId = $this->employee($pdo);
        $this->employmentId = $this->employment($pdo, $employeeId);
        $this->coverage($pdo, $employeeId);
        $this->revisionId = $this->revision($pdo, $employeeId);
        $this->storeResult($employeeId);
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

    public function testCapabilityNamesWhatIsPinnedAndWhatIsNot(): void
    {
        $capability = $this->service->capability();

        self::assertSame('2026-01-01', $capability['shared_data_message_since']);
        self::assertCount(7, $capability['channels']);
        self::assertFalse($capability['automated_dispatch']['supported']);
        self::assertSame(25, $capability['change_codes']['total']);
        // Mapování druh → kód dokládá anotace připnutého XSD, ale jen tam,
        // kde schéma určuje jediný kód; opravy a přestup zůstávají otevřené.
        self::assertSame(
            [
                'employment_start',
                'employment_end',
                'maternity_leave_start',
                'parental_leave_start',
                'maternity_or_parental_leave_end',
            ],
            $capability['change_codes']['mapping_from_duty_documented'],
        );
        foreach ($capability['channels'] as $code => $channel) {
            self::assertFalse(
                $channel['automated_dispatch_documented'],
                "Kanál {$code} se nesmí tvářit jako doložený.",
            );
            self::assertNotSame('', $channel['undocumented_reason_code']);
        }
        foreach ($capability['documents'] as $documentType => $document) {
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{64}$/D',
                $document['schema_sha256'],
                $documentType,
            );
        }
    }

    /**
     * Povinnost a lhůta vznikají i tehdy, když aplikace podání odeslat neumí.
     */
    public function testEmploymentStartRegistersAnObligationWithAnEightDayDeadline(): void
    {
        $registered = $this->service->registerObligations(
            $this->supplierId,
            'production',
            $this->employmentId,
            '2026-06-30',
        );

        self::assertCount(1, $registered);
        self::assertNull($registered[0]['skipped_reason_code']);
        self::assertGreaterThan(0, $registered[0]['obligation_id']);
        self::assertSame(
            'employment_start',
            $registered[0]['duty']['kind'],
        );
        self::assertSame(
            '2026-03-09',
            $registered[0]['duty']['deadline']['due_on'],
        );

        $row = $this->db->pdo()->prepare(
            'SELECT agenda_code, subject_reference, preferred_channel
               FROM payroll_obligations
              WHERE supplier_id = ? AND id = ?',
        );
        $row->execute([$this->supplierId, $registered[0]['obligation_id']]);
        $obligation = $row->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($obligation);
        self::assertSame('HOZ_2026', $obligation['agenda_code']);
        self::assertSame(
            'employment:' . $this->employmentId,
            $obligation['subject_reference'],
        );
        self::assertSame('health_portal', $obligation['preferred_channel']);
    }

    public function testRegisteringTheSameDutyTwiceIsIdempotent(): void
    {
        $first = $this->service->registerObligations(
            $this->supplierId,
            'production',
            $this->employmentId,
            '2026-06-30',
        );
        $second = $this->service->registerObligations(
            $this->supplierId,
            'production',
            $this->employmentId,
            '2026-06-30',
        );

        self::assertSame(
            $first[0]['obligation_id'],
            $second[0]['obligation_id'],
        );
    }

    /**
     * Jádro řezu: artefakt vznikne a uloží se, ale bez připnutého XSD se
     * podání nesmí označit za ověřené — zůstane v `draft` s blokující
     * výhradou ve fázi `xsd`.
     */
    public function testPaymentOverviewFreezesTheArtefactAndStopsBeforeValidated(): void
    {
        $result = $this->service->preparePaymentOverview(
            $this->supplierId,
            'production',
            $this->revisionId,
            '111',
        );

        self::assertTrue($result['created']);
        self::assertSame('PPZ_2026', $result['agenda_code']);
        self::assertSame('2026-06', $result['period']);
        self::assertSame('2026-07-20', $result['deadline']['due_on']);
        self::assertGreaterThan(0, $result['artifact_id']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $result['artifact_sha256'],
        );
        self::assertFalse($result['dispatch']['supported']);

        $bundleAvailable = $this->schemas->isBundleAvailable();
        self::assertSame($bundleAvailable, $result['schema_validated']);
        self::assertSame(
            $bundleAvailable ? 'ready' : 'draft',
            $result['status'],
        );

        // Artefakt je uložený a čitelný bez ohledu na to, jestli XSD je.
        $xml = $this->submissions->artifactBytes(
            $this->supplierId,
            (int) $result['artifact_id'],
        );
        self::assertStringContainsString(
            '<prehledPlatbyZamestnavatele',
            $xml,
        );
        self::assertStringContainsString(
            '<identifikacniCisloPlatce>1234567800</identifikacniCisloPlatce>',
            $xml,
        );
        self::assertStringContainsString(
            '<soucetPojistneho>1350</soucetPojistneho>',
            $xml,
        );
        self::assertSame(
            $result['artifact_sha256'],
            hash('sha256', $xml),
        );

        if (!$bundleAvailable) {
            $issues = $this->db->pdo()->prepare(
                'SELECT severity, validation_stage, issue_code
                   FROM payroll_submission_issues
                  WHERE supplier_id = ? AND submission_id = ?',
            );
            $issues->execute([
                $this->supplierId,
                $result['submission_id'],
            ]);
            $issue = $issues->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray(
                $issue,
                'Chybějící XSD musí zůstat zapsané jako výhrada.',
            );
            self::assertSame('blocker', $issue['severity']);
            self::assertSame('xsd', $issue['validation_stage']);
            self::assertSame(
                'zp_schema_bundle_missing',
                $issue['issue_code'],
            );
        }
    }

    public function testPreparingTheSameOverviewTwiceReplaysInsteadOfDuplicating(): void
    {
        $first = $this->service->preparePaymentOverview(
            $this->supplierId,
            'production',
            $this->revisionId,
            '111',
        );
        $second = $this->service->preparePaymentOverview(
            $this->supplierId,
            'production',
            $this->revisionId,
            '111',
        );

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['submission_id'], $second['submission_id']);
        self::assertSame(
            $first['artifact_sha256'],
            $second['artifact_sha256'],
        );
    }

    public function testInsurerWithoutAnOverviewIsRefused(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->service->preparePaymentOverview(
            $this->supplierId,
            'production',
            $this->revisionId,
            '205',
        );
    }

    public function testUnknownInsurerCodeIsRefusedBeforeAnythingIsWritten(): void
    {
        try {
            $this->service->preparePaymentOverview(
                $this->supplierId,
                'production',
                $this->revisionId,
                '999',
            );
            self::fail('Kód mimo datovou větu nesmí projít.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_insurer_code_unknown', $e->errorCode);
        }
    }

    public function testMissingBusinessIdStopsTheSubmissionWithAnActionableReason(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET ic = "" WHERE id = ?',
        )->execute([$this->supplierId]);

        try {
            $this->service->preparePaymentOverview(
                $this->supplierId,
                'production',
                $this->revisionId,
                '111',
            );
            self::fail('Bez IČO nelze sestavit číslo plátce.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_payer_business_id_missing', $e->errorCode);
        }
    }

    // --- fixtures --------------------------------------------------------

    private function employee(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba ZP", "employee", "hpp",
                     1, 1, 0, 10000, 0, 1)',
        )->execute([$this->supplierId]);

        return (int) $pdo->lastInsertId();
    }

    private function employment(PDO $pdo, int $employeeId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 is_primary, start_date)
             VALUES (?, ?, "ZP-1", "employment", "active", 1, "2026-03-01")',
        )->execute([$this->supplierId, $employeeId]);

        return (int) $pdo->lastInsertId();
    }

    private function coverage(PDO $pdo, int $employeeId): void
    {
        $pdo->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction, insurer_status,
                 insurer_code, insurer_evidence_reference, effective_from)
             VALUES (?, ?, "czech_regime_verified", "verified", "111",
                     "synteticky-doklad", "2026-01-01")',
        )->execute([$this->supplierId, $employeeId]);
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
                "synthetic-zp-submission:{$this->supplierId}:{$runId}",
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

    private function storeResult(int $employeeId): void
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
                        'full_name' => 'Syntetická osoba ZP',
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
}
