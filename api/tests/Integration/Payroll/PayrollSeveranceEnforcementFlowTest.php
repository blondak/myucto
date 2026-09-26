<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\Document\EmploymentExitDocumentService;
use MyInvoice\Service\Payroll\Garnishment\EnforcementTerminationNoticeService;
use MyInvoice\Service\Payroll\Termination\PayrollEmploymentTerminationService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Scénář skončení povinného s exekucí a odstupným:
 *
 *   exekuce → skončení výpovědí z organizačních důvodů → odstupné 3× průměr
 *   → mzdový běh za měsíc skončení → srážky z odstupného zvlášť z každého
 *   násobku (§ 299 odst. 4 o. s. ř.) bez paušálu plátce (§ 301 odst. 2)
 *   → zápočtový list s pokračujícími srážkami (exekuce, dohoda o srážkách)
 *   → oznámení soudu/exekutorovi o skončení (§ 295 odst. 2 o. s. ř.).
 *
 * Syntetický vztah od 1. 4. 2022 do 31. 7. 2026, mzda 40 000 Kč, schválený
 * průměr za 3. čtvrtletí 2026 750 Kč/h, tedy průměrný měsíční výdělek
 * 750 × 40 × 4,348 = 130 440 Kč a odstupné 391 320 Kč.
 */
#[Group('integration')]
final class PayrollSeveranceEnforcementFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const WAGE = 4_000_000;
    private const MONTHLY_AVERAGE = 13_044_000;
    /** Paušál plátce mzdy za měsíc (§ 301 odst. 2 o. s. ř.), jen jednou ze mzdy. */
    private const EMPLOYER_FEE = 5_000;

    private string $dataDir;
    private string|false $previousDataDir;

    protected function setUp(): void
    {
        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        $this->dataDir = sys_get_temp_dir() . '/myucto-severance-flow-' . bin2hex(random_bytes(6));
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);
        $this->bootPayrollFullFlow();
        if (!$this->db->hasTable('payroll_employment_terminations')
            || !$this->db->hasTable('payroll_enforcement_termination_notices')
        ) {
            self::markTestSkipped('Migrace skončení vztahu nebo oznámení § 295 neproběhly.');
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
        self::removeDirectory($this->dataDir);
        $this->previousDataDir === false
            ? putenv('MYINVOICE_DATA_DIR')
            : putenv('MYINVOICE_DATA_DIR=' . $this->previousDataDir);
    }

    public function testSeveranceIsGarnishedPerMultipleAndFlowsToExitDocuments(): void
    {
        $officeId = $this->createOffice();
        $this->configureSocialInsuranceOutput($officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employer_settings
                SET payroll_contact_name = "Syntetická mzdová účetní",
                    payroll_contact_email = "mzdy@example.invalid",
                    payroll_contact_phone = "+420 000 000 000"
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);

        $person = $this->createEmployment(
            $officeId,
            'Syntetický Povinný',
            1,
            'hpp',
            'employment',
            40,
            10000,
            periodStart: '2026-07-01',
        );
        $this->backdateEmployment($person, '2022-04-01');
        $this->completeJmhzEmployment($person);
        $this->personAddress($person['employee_id']);
        $wage = $this->createComponent('MZDA', 'base_wage', 'regular');
        $this->createApprovedInput($person, $wage, self::WAGE, 'severance-flow-wage', '2026-07-01');
        $this->createApprovedAverage($person['employment_id'], 3);

        [$caseId, $claimId] = $this->enforcementCase($person['employee_id']);
        $agreementId = $this->deductionAgreement($person['employee_id']);

        // ── Skončení a odstupné ────────────────────────────────────────────
        $this->endEmployment($person['employment_id'], '2026-07-31');
        $termination = $this->service(PayrollEmploymentTerminationService::class);
        $proposal = $termination->save($this->supplierId, $person['employment_id'], [
            'termination_method' => 'employer_notice',
            'legal_ground' => 'organizational',
        ], $this->actors[0]);
        self::assertSame(3, $proposal['severance']['multiple'], 'Poměr trval přes dva roky (§ 67 ZP).');
        self::assertSame(3 * self::MONTHLY_AVERAGE, $proposal['severance']['amount_minor']);
        self::assertSame(3, $proposal['severance']['garnishment_multiple']);

        $severance = $termination->createSeveranceInput(
            $this->supplierId,
            $person['employment_id'],
            $this->actors[0],
            3,
        );
        self::assertSame('created', $severance['severance']['state']);
        self::assertSame('2026-10-31', $severance['severance']['garnishment_period_to']);

        // ── Mzdový běh za měsíc skončení ───────────────────────────────────
        $run = $this->runPayrollMonth('2026-07-01', '2026-08-10', $officeId, 'severance-flow');
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet běhu s odstupným a exekucí.');
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování čekající na potvrzení.');
        self::assertNotNull($run['approved']);

        $snapshot = $run['calculated']->revision['result_snapshot'];
        $people = array_values(array_filter(
            $snapshot['people'],
            static fn (array $row): bool => (int) $row['employee_id'] === $person['employee_id'],
        ));
        self::assertCount(1, $people);
        $input = $people[0]['enforcement']['input'];
        $result = $people[0]['enforcement']['result'];

        $multiples = array_column($input['severance_multiples'], 'amount_minor_units');
        self::assertCount(3, $multiples, 'Odstupné se dělí na tři násobky průměru.');
        self::assertSame($multiples[0], $multiples[1]);
        self::assertSame($multiples[0], $multiples[2]);
        self::assertGreaterThan(0, $multiples[0]);
        self::assertSame('supported', $result['status']);
        self::assertSame(
            self::EMPLOYER_FEE,
            $result['employer_flat_fee_minor_units'],
            'Paušál plátce jen jednou ze mzdy, z násobků odstupného ne.',
        );
        self::assertSame(
            0,
            $result['protected_amount_minor_units'] % 4,
            'Nezabavitelná částka se odečítá čtyřikrát: mzda + tři násobky.',
        );
        $withheld = (int) $result['total_withheld_minor_units'];
        self::assertGreaterThan(self::EMPLOYER_FEE, $withheld);
        self::assertSame(
            $withheld - self::EMPLOYER_FEE,
            array_sum(array_column($this->enforcementLedger($person['employee_id'], 'withheld'), 'amount_minor_units')),
            'Schválení běhu zapíše sraženou částku do exekuční knihy.',
        );
        self::assertSame(
            [self::EMPLOYER_FEE],
            array_column($this->enforcementLedger($person['employee_id'], 'employer_fee'), 'amount_minor_units'),
        );

        // ── Zápočtový list: pokračující srážky ─────────────────────────────
        $exit = $this->service(EmploymentExitDocumentService::class);
        $readiness = $exit->readiness($this->supplierId, $person['employment_id'])['employment_certificate'];
        self::assertTrue($readiness['available'], (string) ($readiness['readiness_code'] ?? ''));
        self::assertSame([$claimId], $readiness['deduction_claim_ids']);
        $sources = $readiness['deduction_sources'];
        self::assertSame(
            [['enforcement_claim', $claimId], ['deduction_agreement', $agreementId]],
            array_map(static fn (array $row): array => [$row['source_kind'], $row['source_claim_id']], $sources),
        );
        $deductions = [];
        foreach ($sources as $source) {
            $deductions[] = [
                'source_claim_id' => $source['source_claim_id'],
                'source_kind' => $source['source_kind'],
                'beneficiary' => $source['beneficiary'] !== '' ? $source['beneficiary'] : 'Syntetický věřitel',
                'ordering_authority' => $source['ordering_authority'] !== ''
                    ? $source['ordering_authority']
                    : 'Syntetický exekutorský úřad',
                'decision_reference' => $source['decision_reference'] !== ''
                    ? $source['decision_reference']
                    : '999 EX 1/26',
            ];
        }
        $certificate = $exit->generateEmploymentCertificate(
            $this->supplierId,
            $person['employment_id'],
            [
                'work_description' => 'Syntetická pracovní činnost',
                'achieved_qualification' => 'Úplné střední odborné vzdělání',
                'exposure_assessment_complete' => true,
                'exposure_facts' => [],
                'deduction_assessment_complete' => true,
                'deductions' => $deductions,
                'pension_category_assessment_complete' => true,
                'pre1993_pension_category_periods' => [],
                'dpp_issuance_basis' => null,
                'correction_reason' => null,
            ],
            'severance-flow-exit',
            $this->actors[0],
        );
        self::assertSame('employment_certificate', $certificate['document_kind']);
        $manifest = json_decode((string) $this->scalar(
            'SELECT source_manifest_json FROM payroll_employment_exit_revisions WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, (int) $certificate['employment_exit_revision_id']],
        ), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $manifest['sources']['deduction_claims']);
        self::assertCount(1, $manifest['sources']['deduction_agreements']);

        // ── Oznámení § 295 odst. 2 o. s. ř. ────────────────────────────────
        $notices = $this->service(EnforcementTerminationNoticeService::class);
        $overview = $notices->overview($this->supplierId, $caseId);
        self::assertNull($overview['blocked_reason']);
        self::assertSame('2026-08-07', $overview['preview']['due_on'], 'Týden od skončení poměru.');
        self::assertSame(
            $withheld - self::EMPLOYER_FEE,
            $overview['preview']['totals']['withheld_minor'],
            'Vyúčtování nese sražené částky z mzdy i odstupného, bez paušálu plátce.',
        );
        $notice = $notices->generate($this->supplierId, $caseId, null, null, $this->actors[0]);
        self::assertSame(1, $notice['revision_no']);
        self::assertStringStartsWith('%PDF-', $notices->pdf($this->supplierId, (int) $notice['id'])['bytes']);
        $sent = $notices->markSent($this->supplierId, (int) $notice['id'], '2026-08-03', 'post', $this->actors[0]);
        self::assertSame('2026-08-03', $sent['sent_on']);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        $service = $this->container->get($class);
        if (!$service instanceof $class) {
            throw new \RuntimeException("Služba {$class} není dostupná.");
        }

        return $service;
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function backdateEmployment(array $person, string $start): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET start_date = ?, actual_start_date = ?
              WHERE supplier_id = ? AND id = ?',
        )->execute([$start, $start, $this->supplierId, $person['employment_id']]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET effective_from = ?, planned_start_on = ?, actual_start_on = ?
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$start, $start, $start, $this->supplierId, $person['employment_id']]);
    }

    private function personAddress(int $employeeId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employees SET birth_date = "1991-02-03" WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $employeeId]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city,
                 postal_code, country_code, effective_from)
             VALUES (?, ?, "residence", "Testovací 10", "Praha", "10000", "CZ", "2022-04-01")',
        )->execute([$this->supplierId, $employeeId]);
    }

    private function endEmployment(int $employmentId, string $endOn): void
    {
        $this->service(PayrollEmploymentRepository::class)->transition(
            $this->supplierId,
            $employmentId,
            'ended',
            (int) $this->scalar(
                'SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?',
                [$this->supplierId, $employmentId],
            ),
            $endOn,
            'Syntetická výpověď z organizačních důvodů',
            $this->actors[0],
            null,
            null,
        );
    }

    /** @return array{0:int,1:int} [case_id, claim_id] */
    private function enforcementCase(int $employeeId): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_enforcement_cases
                (supplier_id, employee_id, case_key, case_kind, status,
                 effective_from, evidence_complete, recipient_verified)
             VALUES (?, ?, ?, "enforcement", "remit", "2026-01-01", 1, 1)',
        )->execute([$this->supplierId, $employeeId, 'case-' . bin2hex(random_bytes(6))]);
        $caseId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_enforcement_claims
                (supplier_id, case_id, claim_key, enforcement_order_key,
                 legal_basis, category, outstanding_minor_units,
                 priority_date, first_payer_delivered_on, order_issued_on,
                 legal_title_verified, order_or_notice_delivered,
                 priority_classification_verified, due_monetary_claim_verified, is_active)
             VALUES (?, ?, ?, ?, "statutory", "non_priority", 50000000,
                     "2026-01-15", "2026-01-15", "2022-01-02", 1, 1, 1, 1, 1)',
        )->execute([
            $this->supplierId,
            $caseId,
            'claim-' . bin2hex(random_bytes(6)),
            'order-' . bin2hex(random_bytes(6)),
        ]);
        $claimId = (int) $pdo->lastInsertId();
        foreach ([
            ['executor', 'Syntetický exekutorský úřad', '999 EX 1/26'],
            ['beneficiary', 'Syntetický věřitel', null],
        ] as [$role, $name, $reference]) {
            $documentId = $this->decisionDocument("{$caseId}-{$role}");
            $pdo->prepare(
                'INSERT INTO payroll_enforcement_case_parties
                    (supplier_id, case_id, party_role, revision_no, effective_from,
                     party_name, party_reference, source_document_id,
                     source_document_sha256, created_by)
                 VALUES (?, ?, ?, 1, "2026-01-01", ?, ?, ?,
                         (SELECT sha256 FROM documents WHERE id = ?), ?)',
            )->execute([
                $this->supplierId,
                $caseId,
                $role,
                $name,
                $reference,
                $documentId,
                $documentId,
                $this->actors[0],
            ]);
        }

        return [$caseId, $claimId];
    }

    private function decisionDocument(string $seed): int
    {
        $hash = hash('sha256', "severance-flow-decision:{$this->supplierId}:{$seed}");
        $this->db->pdo()->prepare(
            'INSERT INTO documents
                (supplier_id, title, original_name, filename, sha256, mime_type,
                 size_bytes, doc_type, source, uploaded_by, scope)
             VALUES (?, ?, "decision.pdf", ?, ?, "application/pdf", 1, "pdf",
                     "manual", ?, "company")',
        )->execute([$this->supplierId, "Syntetické rozhodnutí {$seed}", "{$hash}.pdf", $hash, $this->actors[0]]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Dobrovolná dohoda o srážkách ze mzdy (§ 146 písm. b) ZP). */
    private function deductionAgreement(int $employeeId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_deduction_agreements
                (supplier_id, employee_id, agreement_reference, title, deduction_kind,
                 status, priority_no, requested_minor, total_limit_minor,
                 withheld_total_minor, valid_from, delivered_on, recipient_reference)
             VALUES (?, ?, "SYNTH-DOHODA-1", "Splátka půjčky", "other", "active", 100,
                     200000, 1000000, 200000, "2026-01-01", "2026-01-05", "Syntetický věřitel")',
        )->execute([$this->supplierId, $employeeId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return list<array{entry_kind:string,amount_minor_units:int}> */
    private function enforcementLedger(int $employeeId, string $kind): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ledger.entry_kind, ledger.amount_minor_units
               FROM payroll_enforcement_ledger ledger
               JOIN payroll_enforcement_month_results result
                 ON result.supplier_id = ledger.supplier_id
                AND result.id = ledger.month_result_id
              WHERE ledger.supplier_id = ? AND result.employee_id = ? AND ledger.entry_kind = ?
              ORDER BY ledger.id',
        );
        $stmt->execute([$this->supplierId, $employeeId, $kind]);

        return array_map(
            static fn (array $row): array => [
                'entry_kind' => (string) $row['entry_kind'],
                'amount_minor_units' => (int) $row['amount_minor_units'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private static function removeDirectory(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
