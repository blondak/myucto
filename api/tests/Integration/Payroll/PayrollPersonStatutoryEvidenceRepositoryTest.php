<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Service\Payroll\PayrollPersonStatutoryEvidenceValidator;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollPersonStatutoryEvidenceRepositoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollPersonStatutoryEvidenceRepository $repository;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        $this->db = $db;
        if (!$db->hasTable('payroll_person_social_discount_claims')) {
            $this->markTestSkipped('Migrace 1256 neproběhla.');
        }
        $repository = $container->get(PayrollPersonStatutoryEvidenceRepository::class);
        if (!$repository instanceof PayrollPersonStatutoryEvidenceRepository) {
            throw new \RuntimeException('Repozitář zákonné evidence není dostupný.');
        }
        $this->repository = $repository;

        $pdo = $db->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employeeId = $this->createEmployee($pdo, $this->supplierId);
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

    public function testReturnsEffectiveTenantScopedSnapshotWithoutDefaults(): void
    {
        $this->insertCompleteEvidence($this->db->pdo());

        $snapshot = $this->repository->snapshot(
            $this->supplierId,
            $this->employeeId,
            '2026-06-30',
        );

        self::assertNotNull($snapshot);
        self::assertSame('111', $snapshot['health']['coverage']['insurer_code']);
        self::assertSame(
            'other-employer:synthetic',
            $snapshot['health']['other_employer_bases'][0]['employer_reference'],
        );
        self::assertSame('signed', $snapshot['income_tax']['declaration']['status']);
        self::assertSame('czech-resident', $snapshot['income_tax']['residence']['residence']);
        self::assertSame(
            'czech_regime_verified',
            $snapshot['social']['jurisdiction']['jurisdiction'],
        );
        self::assertSame(
            'verified',
            $snapshot['social']['working_pensioner_discount']['status'],
        );
        self::assertNull($this->repository->snapshot(
            $this->otherSupplierId,
            $this->employeeId,
            '2026-06-30',
        ));
    }

    public function testMissingRowsStayNullAndEmpty(): void
    {
        $snapshot = $this->repository->snapshot(
            $this->supplierId,
            $this->employeeId,
            '2026-06-30',
        );

        self::assertNotNull($snapshot);
        self::assertNull($snapshot['health']['coverage']);
        self::assertSame([], $snapshot['health']['minimum_reductions']);
        self::assertNull($snapshot['income_tax']['declaration']);
        self::assertNull($snapshot['income_tax']['residence']);
        self::assertNull($snapshot['social']['jurisdiction']);
        self::assertNull($snapshot['social']['working_pensioner_discount']);
    }

    public function testRejectsOverlappingHistoricalEvidenceAtReadBoundary(): void
    {
        $pdo = $this->db->pdo();
        $insert = $pdo->prepare(
            'INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, effective_from, effective_to,
                 evidence_reference)
             VALUES (?, ?, "signed", ?, ?, ?)'
        );
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            '2026-01-01',
            '2026-12-31',
            'document:declaration-1',
        ]);
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            '2026-06-01',
            null,
            'document:declaration-2',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('překrývá');
        $this->repository->snapshot(
            $this->supplierId,
            $this->employeeId,
            '2026-06-30',
        );
    }

    /**
     * Řádek, který vznikl PŘED zpřísněním validátoru, nesmí uzamknout stránku.
     *
     * Kombinace „ověřená česká jurisdikce + pojišťovna se netýká“ šla do
     * databáze uložit (CHECK `chk_pp_health_coverage_insurer` váže stav jen na
     * kód a doklad, ne na jurisdikci) a validátor ji dnes odmítá. Kdyby ji
     * `editorView()` pouštěl přes validátor, uživatel by dostal chybu místo
     * formuláře — a jediný nesmysl v evidenci by nešlo opravit. Čtecí cesta
     * proto vrací syrovou historii a rozpor hlásí jen jako blokátor.
     */
    public function testLegacyConflictingCoverageStillOpensTheEditor(): void
    {
        $pdo = $this->db->pdo();
        $this->insertCompleteEvidence($pdo);
        $pdo->prepare(
            'UPDATE payroll_person_health_coverage_history
                SET insurer_status = "not_applicable",
                    insurer_code = NULL,
                    insurer_evidence_reference = NULL
              WHERE supplier_id = ? AND employee_id = ?'
        )->execute([$this->supplierId, $this->employeeId]);

        $view = $this->repository->editorView(
            $this->supplierId,
            $this->employeeId,
            '2026-06-30',
        );

        self::assertNotNull($view);
        self::assertCount(1, $view['sections']['health_coverages']);
        self::assertSame(
            'not_applicable',
            $view['sections']['health_coverages'][0]['insurer_status'],
            'Editor musí ukázat i řádek, který validátor odmítá — jinak ho nejde opravit.',
        );
        self::assertContains(
            'statutory_evidence_snapshot_missing_or_mismatched',
            $view['blockers'],
        );
        // Kontrola, že test měří právě ten rozpor: snímek na něm padá.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nechte jako neověřenou');
        $this->repository->snapshot($this->supplierId, $this->employeeId, '2026-06-30');
    }

    private function insertCompleteEvidence(PDO $pdo): void
    {
        $pdo->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction, insurer_status,
                 insurer_code, insurer_evidence_reference, effective_from)
             VALUES (?, ?, "czech_regime_verified", "verified", "111",
                     "document:health-insurer", "2026-01-01")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_health_minimum_reductions
                (supplier_id, employee_id, reason, evidence_reference, effective_from)
             VALUES (?, ?, "state_insured", "document:state-insured", "2026-01-01")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_health_month_evidence
                (supplier_id, employee_id, period_start,
                 selected_top_up_employer_reference,
                 selected_top_up_employer_evidence_reference)
             VALUES (?, ?, "2026-06-01", "other-employer:synthetic",
                     "document:top-up-selection")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_health_other_employer_bases
                (supplier_id, employee_id, period_start, employer_reference,
                 assessment_base_minor_units, employment_from, evidence_reference)
             VALUES (?, ?, "2026-06-01", "other-employer:synthetic",
                     3000000, "2026-01-01", "document:other-employer")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, effective_from, evidence_reference)
             VALUES (?, ?, "signed", "2026-01-01", "document:tax-declaration")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_residences
                (supplier_id, employee_id, residence, country_code,
                 effective_from, evidence_reference)
             VALUES (?, ?, "czech-resident", "CZ", "2026-01-01",
                     "document:tax-residence")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_credit_claims
                (supplier_id, employee_id, credit_kind, evidence_status,
                 effective_from, evidence_reference)
             VALUES (?, ?, "taxpayer", "verified", "2026-01-01",
                     "document:taxpayer-credit")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_child_claims
                (supplier_id, employee_id, child_reference, child_order, ztp_p,
                 evidence_status, shared_household_confirmed,
                 other_claimant_excluded, effective_from, evidence_reference)
             VALUES (?, ?, "child:synthetic-1", 1, 0, "verified", 1, 1,
                     "2026-01-01", "document:child-claim")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_social_jurisdictions
                (supplier_id, employee_id, jurisdiction, a1_status, effective_from)
             VALUES (?, ?, "czech_regime_verified", "not_applicable", "2026-01-01")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_social_discount_claims
                (supplier_id, employee_id, status, effective_from, evidence_reference)
             VALUES (?, ?, "verified", "2026-01-01",
                     "document:working-pensioner")'
        )->execute([$this->supplierId, $this->employeeId]);
    }

    private function createEmployee(PDO $pdo, int $supplierId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba", "employee", "hpp", 1, 1, 0, 10000, 0, 1)'
        )->execute([$supplierId]);

        return (int) $pdo->lastInsertId();
    }
}
