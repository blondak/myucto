<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupPayrollRunInputSnapshotContract;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollStatutoryResultsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollStatutoryResultsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'revision_id',
        'calculation_kind',
        'schema_version',
        'result_status',
        'ruleset_id',
        'ruleset_hash',
        'input_snapshot_json',
        'input_snapshot_hash',
        'result_snapshot_json',
        'result_snapshot_hash',
        'result_set_hash',
        'created_by',
        'created_at',
    ];

    public function testDeclaresCompleteImmutableRootProjection(): void
    {
        self::assertSame(
            self::COLUMNS,
            CompanyBackupPayrollStatutoryResultsProjection::dataColumns(),
        );
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_statutory_results');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->assertRegistryTargets($registry);
        self::assertSame([
            'created_by->users:id',
            'supplier_id,revision_id->payroll_run_revisions:supplier_id,id',
        ], array_map(
            static fn ($reference): string => $reference->signature(),
            $projection->references->references,
        ));
        self::assertSame([
            'input_snapshot_hash<-sha256_canonical_json:input_snapshot_json!',
            'result_snapshot_hash<-sha256_canonical_json:result_snapshot_json!',
        ], array_map(
            static fn ($hash): string => $hash->signature(),
            $projection->derivedHashes->hashes,
        ));
        self::assertSame([], $projection->generatedColumns);
        self::assertSame([], $projection->omitColumns);
        self::assertSame(['ruleset_id'], $projection->preservedIdentifiers->columns);
    }

    public function testSharesWholeRunInputContractWithRevisionProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $root = $registry->definition('table:payroll_statutory_results');
        $revision = $registry->definition('table:payroll_run_revisions');
        self::assertNotNull($root);
        self::assertNotNull($revision);
        $rootProjection = CompanyBackupTableProjection::fromDefinition($root);
        $revisionProjection = CompanyBackupTableProjection::fromDefinition($revision);

        $rootInputReferences = array_map(
            static fn ($reference): string => $reference->signature(),
            array_values(array_filter(
                $rootProjection->embeddedReferences->references,
                static fn ($reference): bool =>
                    $reference->column === 'input_snapshot_json',
            )),
        );
        $revisionInputReferences = array_map(
            static fn ($reference): string => $reference->signature(),
            array_values(array_filter(
                $revisionProjection->embeddedReferences->references,
                static fn ($reference): bool =>
                    $reference->column === 'input_snapshot_json',
            )),
        );

        self::assertCount(
            count(CompanyBackupPayrollRunInputSnapshotContract::embeddedReferences()),
            $rootInputReferences,
        );
        self::assertSame($rootInputReferences, $revisionInputReferences);
        self::assertSame(
            array_map(
                static fn ($hash): string => $hash->signature(),
                $revisionProjection->embeddedHashes->hashes,
            ),
            array_map(
                static fn ($hash): string => $hash->signature(),
                $rootProjection->embeddedHashes->hashes,
            ),
        );
        self::assertSame(
            array_map(
                static fn ($reference): string => $reference->signature(),
                $revisionProjection->embeddedHashReferences->references,
            ),
            array_map(
                static fn ($reference): string => $reference->signature(),
                $rootProjection->embeddedHashReferences->references,
            ),
        );
    }

    public function testImmutableGraphForbidsDeferredUpdates(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        foreach ([
            'table:payroll_run_revisions',
            'table:payroll_statutory_accumulator_entries',
            'table:payroll_statutory_accumulator_openings',
            'table:payroll_statutory_person_results',
            'table:payroll_statutory_relationship_results',
            'table:payroll_statutory_results',
        ] as $registryKey) {
            $definition = $registry->definition($registryKey);
            self::assertNotNull($definition);
            self::assertFalse(
                CompanyBackupTableProjection::fromDefinition($definition)
                    ->allowsDeferredUpdates,
                $registryKey,
            );
        }
    }
}
