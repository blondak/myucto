<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceCollector;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceIntegrityValidator;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceOccurrence;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentity;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentityLookup;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentityProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceKey;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSourceReferenceIntegrityTest extends TestCase
{
    public function testProjectsEveryDeclaredIdentityOfSourceRow(): void
    {
        $projection = CompanyBackupSourceIdentityProjection::fromDefinition(
            $this->tenantDefinition(),
        );

        $identity = $projection->identityForRow([
            'code' => 'EMP-1',
            'id' => 31,
            'owner' => 'primary',
            'supplier_id' => 7,
        ]);

        self::assertSame(['id' => 31], $identity->primaryKey->values);
        self::assertSame(
            ['supplier_id' => 7, 'id' => 31],
            $identity->tenantScopedPrimaryKey?->values,
        );
        self::assertSame(
            ['supplier_id' => 7, 'code' => 'EMP-1'],
            $identity->naturalKey?->values,
        );
        self::assertSame(
            [['supplier_id' => 7, 'id' => 31, 'owner' => 'primary']],
            array_map(
                static fn (CompanyBackupSourceKey $key): array => $key->values,
                $identity->referenceKeys,
            ),
        );
        self::assertCount(4, $identity->keys());
        self::assertSame(
            $identity->primaryKey->id,
            CompanyBackupSourceKey::fromRow(
                'table:synthetic_records',
                ['id'],
                ['owner' => 'primary', 'id' => 31],
            )->id,
        );
    }

    public function testRejectsInvalidDeclaredKeyValueWithoutLeakingIt(): void
    {
        try {
            CompanyBackupSourceIdentityProjection::fromDefinition(
                $this->tenantDefinition(),
            )->identityForRow([
                'code' => null,
                'id' => 31,
                'owner' => 'primary',
                'supplier_id' => 7,
            ]);
            self::fail('Neplatný natural key nesmí vstoupit do zdrojového indexu.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('source_key_value_invalid', $e->errorCode);
            self::assertSame('table:synthetic_records', $e->registryKey);
            self::assertSame('code', $e->column);
            self::assertStringNotContainsString('EMP-1', $e->getMessage());
        }
    }

    public function testRejectsKeyMetadataOutsideExportedProjection(): void
    {
        $definition = $this->tenantDefinition(['supplier_id', 'missing_code']);

        try {
            CompanyBackupSourceIdentityProjection::fromDefinition($definition);
            self::fail('Klíč nad neexportovaným sloupcem musí preflight zastavit.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('source_key_metadata_invalid', $e->errorCode);
            self::assertSame('table:synthetic_records', $e->registryKey);
            self::assertSame('missing_code', $e->column);
        }
    }

    public function testValidatesInternalReferenceAgainstSourcePayload(): void
    {
        $supplier = CompanyBackupSourceIdentityProjection::fromDefinition(
            $this->supplierDefinition(),
        )->identityForRow(['id' => 7, 'name' => 'Synthetic supplier']);
        $lookup = new TestCompanyBackupSourceIdentityLookup([$supplier]);
        $validator = new CompanyBackupReferenceIntegrityValidator($lookup);
        $existing = $this->columnOccurrence(
            CompanyBackupReferenceMapping::TenantId,
            'table:supplier',
            ['id'],
            [7],
            'supplier_id',
        );

        self::assertSame($existing, $validator->normalize($existing));

        try {
            $validator->normalize($this->columnOccurrence(
                CompanyBackupReferenceMapping::TenantId,
                'table:supplier',
                ['id'],
                [8],
                'supplier_id',
            ));
            self::fail('Chybějící interní cíl musí preflight zastavit.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('source_reference_unresolved', $e->errorCode);
            self::assertSame('table:source_rows', $e->registryKey);
            self::assertSame('supplier_id', $e->column);
            self::assertStringNotContainsString('8', $e->getMessage());
        }
    }

    public function testRejectsExistingRowReachedThroughWrongKeyKind(): void
    {
        $identity = CompanyBackupSourceIdentityProjection::fromDefinition(
            $this->tenantDefinition(),
        )->identityForRow([
            'code' => 'EMP-1',
            'id' => 31,
            'owner' => 'primary',
            'supplier_id' => 7,
        ]);
        $validator = new CompanyBackupReferenceIntegrityValidator(
            new TestCompanyBackupSourceIdentityLookup([$identity]),
        );

        try {
            $validator->normalize($this->columnOccurrence(
                CompanyBackupReferenceMapping::TenantNaturalKey,
                'table:synthetic_records',
                ['id'],
                [31],
                'employee_code',
            ));
            self::fail('Natural-key reference nesmí použít zdrojové primární ID.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('source_reference_key_mismatch', $e->errorCode);
        }
    }

    public function testAcceptsTenantScopedNaturalAndDeclaredReferenceKeys(): void
    {
        $identity = CompanyBackupSourceIdentityProjection::fromDefinition(
            $this->tenantDefinition(),
        )->identityForRow([
            'code' => 'EMP-1',
            'id' => 31,
            'owner' => 'primary',
            'supplier_id' => 7,
        ]);
        $validator = new CompanyBackupReferenceIntegrityValidator(
            new TestCompanyBackupSourceIdentityLookup([$identity]),
        );
        $occurrences = [
            $this->columnOccurrence(
                CompanyBackupReferenceMapping::TenantId,
                'table:synthetic_records',
                ['supplier_id', 'id'],
                [7, 31],
                ['supplier_id', 'employee_id'],
            ),
            $this->columnOccurrence(
                CompanyBackupReferenceMapping::TenantNaturalKey,
                'table:synthetic_records',
                ['supplier_id', 'code'],
                [7, 'EMP-1'],
                ['supplier_id', 'employee_code'],
            ),
            $this->columnOccurrence(
                CompanyBackupReferenceMapping::TenantReferenceKey,
                'table:synthetic_records',
                ['supplier_id', 'id', 'owner'],
                [7, 31, 'primary'],
                ['supplier_id', 'employee_id', 'employee_owner'],
            ),
        ];

        foreach ($occurrences as $occurrence) {
            self::assertSame($occurrence, $validator->normalize($occurrence));
        }
    }

    public function testNormalizesGlobalIdAndNaturalKeyToOneRequirement(): void
    {
        $country = CompanyBackupSourceIdentityProjection::fromDefinition(
            $this->countryDefinition(),
        )->identityForRow(['id' => 7, 'iso2' => 'CZ', 'name' => 'Synthetic']);
        $lookup = new TestCompanyBackupSourceIdentityLookup([$country]);
        $validator = new CompanyBackupReferenceIntegrityValidator($lookup);
        $byId = $this->columnOccurrence(
            CompanyBackupReferenceMapping::GlobalNaturalKey,
            'table:countries',
            ['id'],
            [7],
            'country_id',
        );
        $embedded = CompanyBackupEmbeddedReferenceSet::fromArray([[
            'column' => 'snapshot_json',
            'condition' => null,
            'fallbacks' => [],
            'mapping' => CompanyBackupReferenceMapping::GlobalNaturalKey->value,
            'nullable' => false,
            'path' => ['country'],
            'target' => 'table:countries',
            'target_columns' => ['iso2'],
        ]], 'table:source_rows', ['snapshot_json'])->references[0];
        $byNaturalKey = CompanyBackupReferenceOccurrence::embedded(
            'table:source_rows',
            $embedded,
            'CZ',
        );

        $collector = new CompanyBackupExternalReferenceCollector();
        $collector->accept($validator->normalize($byId));
        $collector->accept($validator->normalize($byNaturalKey));
        $requirement = $collector->finish()->find(
            CompanyBackupReferenceMapping::GlobalNaturalKey,
            'table:countries',
            ['iso2' => 'CZ'],
        );

        self::assertNotNull($requirement);
        self::assertSame(2, $requirement->occurrenceCount);
        self::assertSame(['iso2' => 'CZ'], $requirement->sourceKey);
    }

    public function testRejectsGlobalMappingToTenantPayload(): void
    {
        $identity = CompanyBackupSourceIdentityProjection::fromDefinition(
            $this->tenantDefinition(),
        )->identityForRow([
            'code' => 'EMP-1',
            'id' => 31,
            'owner' => 'primary',
            'supplier_id' => 7,
        ]);
        $validator = new CompanyBackupReferenceIntegrityValidator(
            new TestCompanyBackupSourceIdentityLookup([$identity]),
        );

        try {
            $validator->normalize($this->columnOccurrence(
                CompanyBackupReferenceMapping::GlobalNaturalKey,
                'table:synthetic_records',
                ['id'],
                [31],
                'global_id',
            ));
            self::fail('Globální mapování nesmí mířit do tenantového payloadu.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('source_reference_policy_mismatch', $e->errorCode);
        }
    }

    public function testLeavesActorDecisionOutsideSourcePayloadLookup(): void
    {
        $lookup = new TestCompanyBackupSourceIdentityLookup([]);
        $occurrence = $this->columnOccurrence(
            CompanyBackupReferenceMapping::Actor,
            'table:users',
            ['id'],
            [9],
            'created_by',
            nullable: true,
            fallbacks: ['restore_actor'],
        );

        self::assertSame(
            $occurrence,
            (new CompanyBackupReferenceIntegrityValidator($lookup))->normalize(
                $occurrence,
            ),
        );
        self::assertSame(0, $lookup->findCalls);
    }

    /** @param list<string>|null $naturalKey */
    private function tenantDefinition(
        ?array $naturalKey = ['supplier_id', 'code'],
    ): TenantDataDefinition {
        return $this->definition(
            'table:synthetic_records',
            TenantDataPolicy::TenantOwned,
            ['id'],
            ['id', 'supplier_id', 'code', 'owner'],
            $naturalKey,
            [['supplier_id', 'id', 'owner']],
            [[
                'columns' => ['supplier_id'],
                'target' => 'table:supplier',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ]],
        );
    }

    private function supplierDefinition(): TenantDataDefinition
    {
        return $this->definition(
            'table:supplier',
            TenantDataPolicy::TenantRoot,
            ['id'],
            ['id', 'name'],
        );
    }

    private function countryDefinition(): TenantDataDefinition
    {
        return $this->definition(
            'table:countries',
            TenantDataPolicy::GlobalReference,
            ['id'],
            ['id', 'iso2', 'name'],
            ['iso2'],
        );
    }

    /**
     * @param list<string> $primaryKey
     * @param list<string> $dataColumns
     * @param list<string>|null $naturalKey
     * @param list<list<string>> $referenceKeys
     * @param list<array<string,mixed>> $references
     */
    private function definition(
        string $registryKey,
        TenantDataPolicy $policy,
        array $primaryKey,
        array $dataColumns,
        ?array $naturalKey = null,
        array $referenceKeys = [],
        array $references = [],
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            $registryKey,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => $primaryKey,
                ...($naturalKey === null ? [] : ['natural_key' => $naturalKey]),
                ...($referenceKeys === [] ? [] : [
                    'reference_keys' => $referenceKeys,
                ]),
                'ownership' => ['strategy' => 'synthetic'],
                'secrets' => [],
                'company_backup' => [
                    'data_columns' => $dataColumns,
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' => $references,
                    'restore_overrides' => [],
                ],
            ],
        );
    }

    /**
     * @param list<string> $targetColumns
     * @param list<int|string> $values
     * @param list<string>|string $columns
     * @param list<string> $fallbacks
     */
    private function columnOccurrence(
        CompanyBackupReferenceMapping $mapping,
        string $target,
        array $targetColumns,
        array $values,
        array|string $columns,
        bool $nullable = false,
        array $fallbacks = [],
    ): CompanyBackupReferenceOccurrence {
        $sourceColumns = is_string($columns) ? [$columns] : $columns;
        $reference = CompanyBackupReferenceSet::fromArray([[
            'columns' => $sourceColumns,
            'target' => $target,
            'target_columns' => $targetColumns,
            'mapping' => $mapping->value,
            'constraint' => $nullable
                ? CompanyBackupReferenceConstraint::Optional->value
                : CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => $nullable ? $sourceColumns : [],
            'fallbacks' => $fallbacks,
        ]], 'table:source_rows')->references[0];

        return CompanyBackupReferenceOccurrence::column(
            'table:source_rows',
            $reference,
            $values,
        );
    }
}

/** @internal Testovací index drží jen několik syntetických identit. */
final class TestCompanyBackupSourceIdentityLookup implements
    CompanyBackupSourceIdentityLookup
{
    /** @var array<string,CompanyBackupSourceIdentity> */
    private array $identities = [];

    public int $findCalls = 0;

    /** @param list<CompanyBackupSourceIdentity> $identities */
    public function __construct(array $identities)
    {
        foreach ($identities as $identity) {
            foreach ($identity->keys() as $key) {
                $this->identities[$key->id] = $identity;
            }
        }
    }

    public function find(CompanyBackupSourceKey $key): ?CompanyBackupSourceIdentity
    {
        $this->findCalls++;
        $identity = $this->identities[$key->id] ?? null;
        return $identity !== null && $identity->hasKey($key) ? $identity : null;
    }
}
