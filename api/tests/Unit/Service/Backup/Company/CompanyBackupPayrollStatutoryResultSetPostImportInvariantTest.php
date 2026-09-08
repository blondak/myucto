<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupDataRowSource;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollStatutoryResultSetAssembler;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollStatutoryResultSetPostImportInvariant;
use MyInvoice\Service\Backup\Company\CompanyBackupPostImportException;
use MyInvoice\Service\Backup\Company\CompanyBackupPostImportInvariantRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollStatutoryResultSetPostImportInvariantTest extends
    TestCase
{
    private PDO $database;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné pro invariantní test.');
        }
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }

    public function testProductionRegistryValidatesCompleteStatutoryResultSet(): void
    {
        $snapshot = $this->snapshot();
        $registry = CompanyBackupPostImportInvariantRegistry::production(
            new StatutoryResultSetPostImportRowSource($this->rows()),
        );
        self::assertTrue($this->database->beginTransaction());

        $report = $registry->validate($this->database, 7, $snapshot);

        self::assertSame([
            [
                'id' => 'accounting.journal-balance',
                'check_count' => 0,
            ],
            [
                'id' => 'payroll.statutory-result-set',
                'check_count' => 3,
            ],
            [
                'id' => 'stock.level-balance',
                'check_count' => 0,
            ],
        ], $report->invariants);
        self::assertSame(3, $report->invariantCount);
        self::assertSame(3, $report->checkCount);
        self::assertTrue($this->database->inTransaction());
        self::assertSame(0, $this->temporaryTableCount());
        self::assertTrue($this->database->rollBack());
    }

    public function testRejectsChangedAggregateAndCleansDiskIndex(): void
    {
        $rows = $this->rows();
        $rows[CompanyBackupPayrollStatutoryResultSetAssembler::ROOT_REGISTRY_KEY]
            [0]['result_set_hash'] = str_repeat('0', 64);
        $invariant =
            new CompanyBackupPayrollStatutoryResultSetPostImportInvariant(
                new StatutoryResultSetPostImportRowSource($rows),
            );
        self::assertTrue($this->database->beginTransaction());

        try {
            $invariant->validate($this->database, 7, $this->snapshot());
            self::fail('Změněná agregátní pečeť musí zablokovat DB commit.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame(
                'post_import_payroll_statutory_result_set_invalid',
                $e->errorCode,
            );
            self::assertSame(
                CompanyBackupPayrollStatutoryResultSetAssembler::ROOT_REGISTRY_KEY,
                $e->registryKey,
            );
            self::assertNotNull($e->getPrevious());
        }

        self::assertTrue($this->database->inTransaction());
        self::assertSame(0, $this->temporaryTableCount());
        self::assertTrue($this->database->rollBack());
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function rows(): array
    {
        $person = [
            'id' => 41,
            'supplier_id' => 7,
            'statutory_result_id' => 31,
            'revision_id' => 51,
            'calculation_kind' => 'social_insurance',
            'employee_id' => 17,
            'result_status' => 'calculated',
            'input_snapshot_json' => CanonicalJson::encode([
                'employee_id' => 17,
            ]),
            'result_snapshot_json' => CanonicalJson::encode([
                'amount_minor' => 1_700,
            ]),
        ];
        $relationship = [
            'id' => 61,
            'supplier_id' => 7,
            'statutory_result_id' => 31,
            'person_result_id' => 41,
            'revision_id' => 51,
            'calculation_kind' => 'social_insurance',
            'employee_id' => 17,
            'employment_id' => 19,
            'result_status' => 'calculated',
            'input_snapshot_json' => CanonicalJson::encode([
                'employment_id' => 19,
            ]),
            'result_snapshot_json' => CanonicalJson::encode([
                'amount_minor' => 190,
            ]),
        ];
        $header = [
            'id' => 31,
            'supplier_id' => 7,
            'revision_id' => 51,
            'calculation_kind' => 'social_insurance',
            'schema_version' => 'payroll-social-result.v1',
            'result_status' => 'calculated',
            'ruleset_id' => 'cz-social-2026.1',
            'ruleset_hash' => str_repeat('a', 64),
            'input_snapshot_json' => CanonicalJson::encode([
                'period' => '2026-06',
            ]),
            'result_snapshot_json' => CanonicalJson::encode([
                'amount_minor' => 1_700,
            ]),
            'result_set_hash' => str_repeat('0', 64),
        ];
        $header['result_set_hash'] =
            CompanyBackupPayrollStatutoryResultSetAssembler::calculate(
                $header,
                [$person],
                [$relationship],
            );

        return [
            CompanyBackupPayrollStatutoryResultSetAssembler::ROOT_REGISTRY_KEY =>
                [$header],
            CompanyBackupPayrollStatutoryResultSetAssembler::PERSON_REGISTRY_KEY =>
                [$person],
            CompanyBackupPayrollStatutoryResultSetAssembler::RELATIONSHIP_REGISTRY_KEY =>
                [$relationship],
        ];
    }

    private function snapshot(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [
                $this->definition('table:supplier', TenantDataPolicy::TenantRoot),
                $this->definition(
                    CompanyBackupPayrollStatutoryResultSetAssembler::ROOT_REGISTRY_KEY,
                    TenantDataPolicy::TenantOwned,
                ),
                $this->definition(
                    CompanyBackupPayrollStatutoryResultSetAssembler::PERSON_REGISTRY_KEY,
                    TenantDataPolicy::TenantOwned,
                ),
                $this->definition(
                    CompanyBackupPayrollStatutoryResultSetAssembler::RELATIONSHIP_REGISTRY_KEY,
                    TenantDataPolicy::TenantOwned,
                ),
            ],
            [$profile],
        ), $profile);
    }

    private function definition(
        string $key,
        TenantDataPolicy $policy,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            $key,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => $policy === TenantDataPolicy::TenantRoot
                        ? 'selected_supplier'
                        : 'supplier_id',
                    'column' => $policy === TenantDataPolicy::TenantRoot
                        ? 'id'
                        : 'supplier_id',
                ],
                'secrets' => [],
            ],
        );
    }

    private function temporaryTableCount(): int
    {
        $statement = $this->database->query(
            "SELECT COUNT(*) FROM sqlite_temp_master WHERE type = 'table'"
                . " AND name LIKE 'company_backup_statutory_%'",
        );
        if ($statement === false) {
            throw new \RuntimeException('Nelze ověřit temporary tabulky.');
        }
        return (int) $statement->fetchColumn();
    }
}

/** @internal */
final readonly class StatutoryResultSetPostImportRowSource implements
    CompanyBackupDataRowSource
{
    /** @param array<string,list<array<string,mixed>>> $rows */
    public function __construct(private array $rows) {}

    public function rows(
        PDO $snapshot,
        int $supplierId,
        TenantDataDefinition $definition,
    ): iterable {
        if ($supplierId !== 7 || !isset($this->rows[$definition->key])) {
            throw new \RuntimeException('synthetic_post_import_source_invalid');
        }
        yield from $this->rows[$definition->key];
    }
}
