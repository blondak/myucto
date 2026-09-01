<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlRowSource;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableSchemaReader;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Živá kontrola úplnosti a tenantové izolace přesčasových evidencí. */
#[Group('integration')]
final class CompanyBackupPayrollOvertimeTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private int $supplierId;
    private int $foreignSupplierId;
    private int $employmentId;
    private int $foreignEmploymentId;
    private int $actorId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        foreach ([
            'payroll_overtime_averaging_periods',
            'payroll_overtime_compensations',
            'payroll_overtime_consents',
            'payroll_overtime_protections',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Migrace přesčasových evidencí neproběhly.');
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplier = $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        );
        $sourceActor = $pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        );
        self::assertInstanceOf(\PDOStatement::class, $sourceSupplier);
        self::assertInstanceOf(\PDOStatement::class, $sourceActor);
        $sourceSupplierId = (int) $sourceSupplier->fetchColumn();
        $this->actorId = (int) $sourceActor->fetchColumn();
        if ($sourceSupplierId <= 0 || $this->actorId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma nebo syntetický actor.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->foreignSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->employmentId = $this->createEmployment(
            $pdo,
            $this->supplierId,
            'SYN-OT-BACKUP',
        );
        $this->foreignEmploymentId = $this->createEmployment(
            $pdo,
            $this->foreignSupplierId,
            'SYN-OT-FOREIGN',
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

    public function testStreamsAllOvertimeEvidenceOnlyForSelectedTenant(): void
    {
        $pdo = $this->db->pdo();
        $ownIds = [
            'payroll_overtime_averaging_periods' => $this->insertAveragingPeriod(
                $pdo,
                $this->supplierId,
                true,
            ),
            'payroll_overtime_compensations' => $this->insertCompensation(
                $pdo,
                $this->supplierId,
                $this->employmentId,
                true,
            ),
            'payroll_overtime_consents' => $this->insertConsent(
                $pdo,
                $this->supplierId,
                $this->employmentId,
                true,
            ),
            'payroll_overtime_protections' => $this->insertProtection(
                $pdo,
                $this->supplierId,
                $this->employmentId,
                true,
            ),
        ];
        $foreignIds = [
            'payroll_overtime_averaging_periods' => $this->insertAveragingPeriod(
                $pdo,
                $this->foreignSupplierId,
                false,
            ),
            'payroll_overtime_compensations' => $this->insertCompensation(
                $pdo,
                $this->foreignSupplierId,
                $this->foreignEmploymentId,
                false,
            ),
            'payroll_overtime_consents' => $this->insertConsent(
                $pdo,
                $this->foreignSupplierId,
                $this->foreignEmploymentId,
                false,
            ),
            'payroll_overtime_protections' => $this->insertProtection(
                $pdo,
                $this->foreignSupplierId,
                $this->foreignEmploymentId,
                false,
            ),
        ];

        $rows = [];
        foreach (array_keys($ownIds) as $table) {
            $rows[$table] = $this->companyRows($pdo, $table);
            self::assertCount(1, $rows[$table]);
            self::assertSame($ownIds[$table], (int) $rows[$table][0]['id']);
            self::assertSame(
                $this->supplierId,
                (int) $rows[$table][0]['supplier_id'],
            );
            self::assertNotSame($foreignIds[$table], (int) $rows[$table][0]['id']);
        }

        $averaging = $rows['payroll_overtime_averaging_periods'][0];
        self::assertSame('2026-01-01', $averaging['valid_from']);
        self::assertSame('2026-12-31', $averaging['valid_to']);
        self::assertSame(52, (int) $averaging['weeks']);
        self::assertSame('collective_agreement', $averaging['basis']);
        self::assertSame('KS/SYN/2026', $averaging['collective_agreement_reference']);
        self::assertSame('Syntetické vyrovnávací období.', $averaging['note']);
        self::assertSame($this->actorId, (int) $averaging['created_by']);

        $compensation = $rows['payroll_overtime_compensations'][0];
        self::assertSame($this->employmentId, (int) $compensation['employment_id']);
        self::assertSame('2026-05-04', $compensation['overtime_date']);
        self::assertSame(120, (int) $compensation['minutes']);
        self::assertSame('2026-05-20', $compensation['granted_on']);
        self::assertSame('NV/SYN/1', $compensation['document_reference']);
        self::assertSame('Syntetické náhradní volno.', $compensation['note']);

        $consent = $rows['payroll_overtime_consents'][0];
        self::assertSame($this->employmentId, (int) $consent['employment_id']);
        self::assertSame('2026-01-01', $consent['valid_from']);
        self::assertSame('2026-06-30', $consent['valid_to']);
        self::assertSame('DOHODA/SYN/1', $consent['document_reference']);
        self::assertSame('Syntetický souhlas s přesčasem.', $consent['note']);

        $protection = $rows['payroll_overtime_protections'][0];
        self::assertSame($this->employmentId, (int) $protection['employment_id']);
        self::assertSame('pregnancy', $protection['protection']);
        self::assertSame('2026-02-01', $protection['valid_from']);
        self::assertSame('2026-10-31', $protection['valid_to']);
        self::assertSame('OCHRANA/SYN/1', $protection['document_reference']);
        self::assertSame('Syntetická ochranná evidence.', $protection['note']);

        foreach ([$compensation, $consent, $protection] as $evidence) {
            self::assertSame(
                $this->actorId,
                (int) $evidence['created_by'],
            );
            self::assertSame(1, (int) $evidence['row_version']);
        }
    }

    /** @return list<array<string,mixed>> */
    private function companyRows(PDO $pdo, string $table): array
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:' . $table);
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($pdo, $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($pdo, $projection),
        );

        return array_values(iterator_to_array(
            (new CompanyBackupSqlRowSource(batchSize: 1))->rows(
                $pdo,
                $this->supplierId,
                $definition,
            ),
        ));
    }

    private function createEmployment(PDO $pdo, int $supplierId, string $code): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)',
        );
        $statement->execute([$supplierId, 'Syntetická osoba ' . $code]);
        $employeeId = (int) $pdo->lastInsertId();
        $statement = $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01")',
        );
        $statement->execute([$supplierId, $employeeId, $code]);

        return (int) $pdo->lastInsertId();
    }

    private function insertAveragingPeriod(
        PDO $pdo,
        int $supplierId,
        bool $own,
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO payroll_overtime_averaging_periods
                (supplier_id, valid_from, valid_to, weeks, basis,
                 collective_agreement_reference, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute($own ? [
            $supplierId,
            '2026-01-01',
            '2026-12-31',
            52,
            'collective_agreement',
            'KS/SYN/2026',
            'Syntetické vyrovnávací období.',
            $this->actorId,
        ] : [
            $supplierId,
            '2027-01-01',
            null,
            26,
            'statutory',
            null,
            null,
            null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertCompensation(
        PDO $pdo,
        int $supplierId,
        int $employmentId,
        bool $own,
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO payroll_overtime_compensations
                (supplier_id, employment_id, overtime_date, minutes, granted_on,
                 document_reference, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute($own ? [
            $supplierId,
            $employmentId,
            '2026-05-04',
            120,
            '2026-05-20',
            'NV/SYN/1',
            'Syntetické náhradní volno.',
            $this->actorId,
        ] : [
            $supplierId,
            $employmentId,
            '2026-05-05',
            60,
            null,
            null,
            null,
            null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertConsent(
        PDO $pdo,
        int $supplierId,
        int $employmentId,
        bool $own,
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO payroll_overtime_consents
                (supplier_id, employment_id, valid_from, valid_to,
                 document_reference, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute($own ? [
            $supplierId,
            $employmentId,
            '2026-01-01',
            '2026-06-30',
            'DOHODA/SYN/1',
            'Syntetický souhlas s přesčasem.',
            $this->actorId,
        ] : [
            $supplierId,
            $employmentId,
            '2027-01-01',
            null,
            null,
            null,
            null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertProtection(
        PDO $pdo,
        int $supplierId,
        int $employmentId,
        bool $own,
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO payroll_overtime_protections
                (supplier_id, employment_id, protection, valid_from, valid_to,
                 document_reference, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute($own ? [
            $supplierId,
            $employmentId,
            'pregnancy',
            '2026-02-01',
            '2026-10-31',
            'OCHRANA/SYN/1',
            'Syntetická ochranná evidence.',
            $this->actorId,
        ] : [
            $supplierId,
            $employmentId,
            'child_under_one',
            '2027-01-01',
            null,
            null,
            null,
            null,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
