<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PHPUnit\Framework\TestCase;

final class CompanyBackupTableProjectionTest extends TestCase
{
    public function testAcceptsExactRuntimeSchemaAndKeepsOnlyDeclaredDataColumns(): void
    {
        $projection = CompanyBackupTableProjection::fromDefinition($this->definition(
            secrets: [
                'api_token' => ['policy' => TenantSecretPolicy::OptionalCredential->value],
                'approval_token_expires_at' => [
                    'policy' => TenantSecretPolicy::NotSecret->value,
                    'reason' => 'expiry_without_token_is_inert',
                ],
            ],
            dataColumns: ['id', 'supplier_id', 'name', 'approval_token_expires_at'],
            generatedColumns: ['normalized_name'],
            omitColumns: ['cache_blob' => 'runtime_cache'],
        ));

        $projection->assertRuntimeSchema(
            [
                'id',
                'supplier_id',
                'name',
                'normalized_name',
                'cache_blob',
                'api_token',
                'approval_token_expires_at',
            ],
            ['normalized_name'],
            ['id'],
        );

        self::assertSame(
            ['id', 'supplier_id', 'name', 'approval_token_expires_at'],
            $projection->dataColumns,
        );
        self::assertSame([], $projection->embeddedReferences->references);
        self::assertNull($projection->requiredSecretEnvelopeColumn());
    }

    public function testRejectsNewRuntimeColumnInsteadOfExportingItImplicitly(): void
    {
        $projection = CompanyBackupTableProjection::fromDefinition($this->definition());

        try {
            $projection->assertRuntimeSchema(
                ['id', 'supplier_id', 'name', 'new_business_value'],
                [],
                ['id'],
            );
            self::fail('Nový sloupec bez registrace nesmí projít do zálohy implicitně.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_schema_column_unclassified', $e->errorCode);
            self::assertSame('new_business_value', $e->column);
        }
    }

    public function testRejectsSecretLikeRuntimeColumnWithoutExplicitPolicy(): void
    {
        $projection = CompanyBackupTableProjection::fromDefinition($this->definition());

        try {
            $projection->assertRuntimeSchema(
                ['id', 'supplier_id', 'name', 'webhook_token'],
                [],
                ['id'],
            );
            self::fail('Neznámý secret sloupec nesmí skončit v běžném JSONL.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_secret_column_unclassified', $e->errorCode);
            self::assertSame('webhook_token', $e->column);
        }
    }

    public function testRejectsReferenceLikeColumnWithoutExplicitRemapPolicy(): void
    {
        try {
            CompanyBackupTableProjection::fromDefinition($this->definition(
                references: [],
            ));
            self::fail('Exportovaný supplier_id nesmí zůstat bez remap politiky.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_column_unclassified', $e->errorCode);
            self::assertSame('supplier_id', $e->column);
        }
    }

    public function testPolymorphicContractClassifiesReferenceLikeColumn(): void
    {
        $projection = CompanyBackupTableProjection::fromDefinition($this->definition(
            dataColumns: ['id', 'supplier_id', 'source_type', 'source_id'],
            polymorphicReferences: [[
                'column' => 'source_id',
                'discriminator_column' => 'source_type',
                'nullable' => true,
                'cases' => [[
                    'base' => 0,
                    'equals' => 'manual',
                    'mapping' => 'preserve',
                    'multiplier' => 1,
                    'slots' => [],
                    'target' => null,
                    'target_columns' => [],
                    'transform' => 'identity',
                ]],
            ]],
        ));

        self::assertSame(
            ['source_id'],
            $projection->polymorphicReferences->classifiedColumns(),
        );
    }

    public function testPreservedIdentifiersClassifyExternalIdsWithoutRemapping(): void
    {
        $projection = CompanyBackupTableProjection::fromDefinition($this->definition(
            dataColumns: [
                'id',
                'supplier_id',
                'fakturoid_id',
                'idoklad_id',
            ],
            preservedIdentifiers: ['fakturoid_id', 'idoklad_id'],
        ));

        self::assertSame(
            ['fakturoid_id', 'idoklad_id'],
            $projection->preservedIdentifiers->columns,
        );
    }

    public function testRejectsPreservedIdentifierOutsideProjection(): void
    {
        try {
            CompanyBackupTableProjection::fromDefinition($this->definition(
                preservedIdentifiers: ['idoklad_id'],
            ));
            self::fail('Zachovaný externí identifikátor musí být exportovaným sloupcem.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame(
                'data_preserved_identifier_source_not_exported',
                $e->errorCode,
            );
            self::assertSame('idoklad_id', $e->column);
        }
    }

    public function testRejectsNonCanonicalPreservedIdentifierMetadata(): void
    {
        foreach (
            [
                ['idoklad_id', 'fakturoid_id'],
                ['external_reference'],
                ['idoklad_id', 'idoklad_id'],
            ] as $preservedIdentifiers
        ) {
            try {
                CompanyBackupTableProjection::fromDefinition($this->definition(
                    dataColumns: [
                        'id',
                        'supplier_id',
                        'external_reference',
                        'fakturoid_id',
                        'idoklad_id',
                    ],
                    preservedIdentifiers: $preservedIdentifiers,
                ));
                self::fail(
                    'Externí ID musí mít jednoznačný kanonický fingerprintovaný seznam.',
                );
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame(
                    'data_preserved_identifier_metadata_invalid',
                    $e->errorCode,
                );
            }
        }
    }

    public function testRejectsReferenceAndPreservedIdentifierClaimOfSameColumn(): void
    {
        try {
            CompanyBackupTableProjection::fromDefinition($this->definition(
                dataColumns: ['id', 'supplier_id', 'idoklad_id'],
                references: [
                    [
                        'columns' => ['idoklad_id'],
                        'target' => 'table:supplier',
                        'target_columns' => ['id'],
                        'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                        'constraint' => CompanyBackupReferenceConstraint::Required->value,
                        'nullable_columns' => [],
                        'fallbacks' => [],
                    ],
                    $this->supplierReference(),
                ],
                preservedIdentifiers: ['idoklad_id'],
            ));
            self::fail('Externí ID nesmí být současně zachované a remapované.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame(
                'data_reference_column_classification_duplicate',
                $e->errorCode,
            );
            self::assertSame('idoklad_id', $e->column);
        }
    }

    public function testRejectsPolymorphicAndPreservedIdentifierClaimOfSameColumn(): void
    {
        try {
            CompanyBackupTableProjection::fromDefinition($this->definition(
                dataColumns: ['id', 'supplier_id', 'source_type', 'source_id'],
                polymorphicReferences: [[
                    'column' => 'source_id',
                    'discriminator_column' => 'source_type',
                    'nullable' => true,
                    'cases' => [[
                        'base' => 0,
                        'equals' => 'external',
                        'mapping' => 'preserve',
                        'multiplier' => 1,
                        'slots' => [],
                        'target' => null,
                        'target_columns' => [],
                        'transform' => 'identity',
                    ]],
                ]],
                preservedIdentifiers: ['source_id'],
            ));
            self::fail('Externí ID nesmí mít současně polymorfní kontrakt.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame(
                'data_reference_column_classification_duplicate',
                $e->errorCode,
            );
            self::assertSame('source_id', $e->column);
        }
    }

    public function testRejectsOrdinaryAndPolymorphicClaimOfSameColumn(): void
    {
        try {
            CompanyBackupTableProjection::fromDefinition($this->definition(
                dataColumns: ['id', 'supplier_id', 'source_type', 'source_id'],
                references: [
                    [
                        'columns' => ['source_id'],
                        'target' => 'table:supplier',
                        'target_columns' => ['id'],
                        'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                        'constraint' => CompanyBackupReferenceConstraint::Required->value,
                        'nullable_columns' => [],
                        'fallbacks' => [],
                    ],
                    $this->supplierReference(),
                ],
                polymorphicReferences: [[
                    'column' => 'source_id',
                    'discriminator_column' => 'source_type',
                    'nullable' => true,
                    'cases' => [[
                        'base' => 0,
                        'equals' => 'manual',
                        'mapping' => 'preserve',
                        'multiplier' => 1,
                        'slots' => [],
                        'target' => null,
                        'target_columns' => [],
                        'transform' => 'identity',
                    ]],
                ]],
            ));
            self::fail('Jeden sloupec nesmí mít běžný i polymorfní referenční kontrakt.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame(
                'data_reference_column_classification_duplicate',
                $e->errorCode,
            );
            self::assertSame('source_id', $e->column);
        }
    }

    public function testRejectsPrimaryKeyAndGeneratedColumnDrift(): void
    {
        $projection = CompanyBackupTableProjection::fromDefinition($this->definition(
            generatedColumns: ['normalized_name'],
        ));

        try {
            $projection->assertRuntimeSchema(
                ['id', 'supplier_id', 'name', 'normalized_name'],
                [],
                ['supplier_id', 'id'],
            );
            self::fail('Změna generovaného sloupce musí zastavit export.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_generated_columns_mismatch', $e->errorCode);
        }

        try {
            $projection->assertRuntimeSchema(
                ['id', 'supplier_id', 'name', 'normalized_name'],
                ['normalized_name'],
                ['supplier_id', 'id'],
            );
            self::fail('Změna primárního klíče musí zastavit export.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_primary_key_mismatch', $e->errorCode);
        }
    }

    public function testRequiresEnvelopeForProtectedDomainSecret(): void
    {
        $projection = CompanyBackupTableProjection::fromDefinition($this->definition(
            secrets: [
                'protected_value_enc' => [
                    'policy' => TenantSecretPolicy::ProtectedDomainSecret->value,
                ],
            ],
        ));

        self::assertSame('protected_value_enc', $projection->requiredSecretEnvelopeColumn());
    }

    public function testProductionDraftCannotBeStreamedBeforeColumnInventoryIsExplicit(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:supplier');
        self::assertNotNull($definition);

        try {
            CompanyBackupTableProjection::fromDefinition($definition);
            self::fail('Draft company profilu bez explicitních sloupců nesmí vytvořit SQL projekci.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_projection_missing', $e->errorCode);
        }
    }

    /**
     * @param array<string,array{policy:string,reason?:string}> $secrets
     * @param list<string> $dataColumns
     * @param list<string> $generatedColumns
     * @param array<string,string> $omitColumns
     * @param list<array<string,mixed>>|null $references
     * @param list<array<string,mixed>> $polymorphicReferences
     * @param list<string> $preservedIdentifiers
     */
    private function definition(
        array $secrets = [],
        array $dataColumns = ['id', 'supplier_id', 'name'],
        array $generatedColumns = [],
        array $omitColumns = [],
        ?array $references = null,
        array $polymorphicReferences = [],
        array $preservedIdentifiers = [],
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:synthetic_records',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => $secrets,
                'company_backup' => [
                    'data_columns' => $dataColumns,
                    'embedded_references' => [],
                    'generated_columns' => $generatedColumns,
                    'omit_columns' => $omitColumns,
                    ...($polymorphicReferences === [] ? [] : [
                        'polymorphic_references' => $polymorphicReferences,
                    ]),
                    ...($preservedIdentifiers === [] ? [] : [
                        'preserved_identifiers' => $preservedIdentifiers,
                    ]),
                    'references' => $references ?? [$this->supplierReference()],
                    'restore_overrides' => [],
                ],
            ],
        );
    }

    /** @return array<string,mixed> */
    private function supplierReference(): array
    {
        return [
            'columns' => ['supplier_id'],
            'target' => 'table:supplier',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ];
    }
}
