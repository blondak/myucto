<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedHashReference;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceOccurrence;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretStorage;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceKey;
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

    public function testBindsDerivedHashesToExportedColumns(): void
    {
        $projection = CompanyBackupTableProjection::fromDefinition($this->definition(
            dataColumns: ['id', 'supplier_id', 'payload_json', 'payload_hash'],
            derivedHashes: [[
                'algorithm' => 'sha256_canonical_json',
                'hash_column' => 'payload_hash',
                'nullable' => false,
                'source_column' => 'payload_json',
            ]],
        ));

        self::assertSame(
            'payload_hash<-sha256_canonical_json:payload_json!',
            $projection->derivedHashes->hashes[0]->signature(),
        );
    }

    public function testRemapsEmbeddedIdentityAndRefreshesDerivedHashAtomically(): void
    {
        $projection = CompanyBackupTableProjection::fromDefinition($this->definition(
            dataColumns: ['id', 'supplier_id', 'payload_json', 'payload_hash'],
            derivedHashes: [[
                'algorithm' => 'sha256_canonical_json',
                'hash_column' => 'payload_hash',
                'nullable' => false,
                'source_column' => 'payload_json',
            ]],
            embeddedReferences: [[
                'column' => 'payload_json',
                'condition' => null,
                'fallbacks' => [],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'nullable' => false,
                'path' => ['people', '*', 'person_reference'],
                'target' => 'table:synthetic_records',
                'target_columns' => ['id'],
                'value_prefix' => 'employee:',
            ]],
        ));
        $json = \MyInvoice\Service\Backup\CanonicalJson::encode([
            'people' => [['person_reference' => 'employee:17']],
        ]);

        $restored = $projection->remapEmbeddedReferences(
            [
                'id' => 3,
                'supplier_id' => 7,
                'payload_json' => $json,
                'payload_hash' => hash('sha256', $json),
            ],
            static fn ($reference, int|string $value): int => (int) $value + 100,
        );

        self::assertSame(
            'employee:117',
            json_decode(
                (string) $restored['payload_json'],
                true,
                flags: JSON_THROW_ON_ERROR,
            )['people'][0]['person_reference'],
        );
        self::assertSame(
            hash('sha256', (string) $restored['payload_json']),
            $restored['payload_hash'],
        );
    }

    public function testRefreshesNestedSealBeforeOuterSealAtomically(): void
    {
        $projection = CompanyBackupTableProjection::fromDefinition($this->definition(
            dataColumns: ['id', 'supplier_id', 'payload_json', 'payload_hash'],
            derivedHashes: [[
                'algorithm' => 'sha256_canonical_json',
                'hash_column' => 'payload_hash',
                'nullable' => false,
                'source_column' => 'payload_json',
            ]],
            embeddedHashes: [[
                'algorithm' => 'sha256_canonical_json',
                'column' => 'payload_json',
                'dependencies' => [],
                'hash_path' => [
                    'people', '*', 'inputs', '*', 'component_snapshot_hash',
                ],
                'name' => 'component_snapshot',
                'nullable' => false,
                'omit_paths' => [],
                'source_path' => ['people', '*', 'inputs', '*', 'component'],
            ]],
            embeddedReferences: [[
                'column' => 'payload_json',
                'condition' => null,
                'fallbacks' => [],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'nullable' => false,
                'path' => [
                    'people', '*', 'inputs', '*', 'component', 'component_id',
                ],
                'target' => 'table:synthetic_records',
                'target_columns' => ['id'],
            ]],
        ));
        $component = ['code' => 'base_wage', 'component_id' => 17];
        $json = \MyInvoice\Service\Backup\CanonicalJson::encode([
            'people' => [['inputs' => [[
                'component' => $component,
                'component_snapshot_hash' => hash(
                    'sha256',
                    \MyInvoice\Service\Backup\CanonicalJson::encode($component),
                ),
            ]]]],
        ]);

        $restored = $projection->remapEmbeddedReferences(
            [
                'id' => 3,
                'supplier_id' => 7,
                'payload_json' => $json,
                'payload_hash' => hash('sha256', $json),
            ],
            static fn ($reference, int|string $value): int => (int) $value + 100,
        );

        $payload = json_decode(
            (string) $restored['payload_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $input = $payload['people'][0]['inputs'][0];
        self::assertSame(117, $input['component']['component_id']);
        self::assertSame(
            hash(
                'sha256',
                \MyInvoice\Service\Backup\CanonicalJson::encode($input['component']),
            ),
            $input['component_snapshot_hash'],
        );
        self::assertSame(
            hash('sha256', (string) $restored['payload_json']),
            $restored['payload_hash'],
        );
    }

    public function testRemapsCrossRowHashBeforeNestedAndOuterSealAtomically(): void
    {
        $path = ['state', 'approved_results', '*'];
        $projection = CompanyBackupTableProjection::fromDefinition($this->definition(
            dataColumns: ['id', 'supplier_id', 'payload_json', 'payload_hash'],
            derivedHashes: [[
                'algorithm' => 'sha256_canonical_json',
                'hash_column' => 'payload_hash',
                'nullable' => false,
                'source_column' => 'payload_json',
            ]],
            embeddedHashes: [[
                'algorithm' => 'sha256_canonical_json',
                'column' => 'payload_json',
                'dependencies' => [],
                'hash_path' => [...$path, 'record_hash'],
                'name' => 'approved_record',
                'nullable' => false,
                'omit_paths' => [['record_hash']],
                'source_path' => $path,
            ]],
            embeddedHashReferences: [[
                'column' => 'payload_json',
                'nullable' => false,
                'path' => [...$path, 'source_result_hash'],
                'target' => 'table:payroll_statutory_person_results',
                'target_hash_column' => 'result_snapshot_hash',
            ]],
        ));
        $sourceHash = str_repeat('a', 64);
        $targetHash = str_repeat('b', 64);
        $record = [
            'amount_minor' => 4200,
            'record_hash' => '',
            'source_result_hash' => $sourceHash,
        ];
        $recordPayload = $record;
        unset($recordPayload['record_hash']);
        $record['record_hash'] = hash(
            'sha256',
            \MyInvoice\Service\Backup\CanonicalJson::encode($recordPayload),
        );
        $json = \MyInvoice\Service\Backup\CanonicalJson::encode([
            'state' => ['approved_results' => [$record]],
        ]);
        $row = [
            'id' => 3,
            'supplier_id' => 7,
            'payload_json' => $json,
            'payload_hash' => hash('sha256', $json),
        ];

        try {
            $projection->remapEmbeddedReferences(
                $row,
                static fn (): never => throw new \LogicException('Bez ID referencí.'),
            );
            self::fail('Projekce s hashovou referencí musí vyžadovat hashovou mapu.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame(
                'data_embedded_hash_reference_mapper_missing',
                $e->errorCode,
            );
        }

        $restored = $projection->remapEmbeddedReferences(
            $row,
            static fn (): never => throw new \LogicException('Bez ID referencí.'),
            static function (
                CompanyBackupEmbeddedHashReference $reference,
                string $hash,
            ) use ($sourceHash, $targetHash): string {
                self::assertSame('result_snapshot_hash', $reference->targetHashColumn);
                self::assertSame($sourceHash, $hash);
                return $targetHash;
            },
        );

        $payload = json_decode(
            (string) $restored['payload_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $record = $payload['state']['approved_results'][0];
        self::assertSame($targetHash, $record['source_result_hash']);
        $recordHash = $record['record_hash'];
        unset($record['record_hash']);
        self::assertSame(
            hash(
                'sha256',
                \MyInvoice\Service\Backup\CanonicalJson::encode($record),
            ),
            $recordHash,
        );
        self::assertSame(
            hash('sha256', (string) $restored['payload_json']),
            $restored['payload_hash'],
        );
    }

    public function testRemapsEveryReferenceShapeAndRefreshesOuterSeal(): void
    {
        $projection = CompanyBackupTableProjection::fromDefinition($this->definition(
            dataColumns: [
                'id',
                'supplier_id',
                'external_reference',
                'source_type',
                'source_id',
                'payload_json',
                'payload_hash',
            ],
            encodedReferences: [[
                'column' => 'external_reference',
                'condition' => null,
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'nullable' => false,
                'target' => 'table:synthetic_records',
                'target_columns' => ['id'],
                'value_prefix' => 'employee:',
                'value_suffix_separator' => null,
            ]],
            derivedHashes: [[
                'algorithm' => 'sha256_canonical_json',
                'hash_column' => 'payload_hash',
                'nullable' => false,
                'source_column' => 'payload_json',
            ]],
            embeddedReferences: [[
                'column' => 'payload_json',
                'condition' => null,
                'fallbacks' => [],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'nullable' => false,
                'path' => ['person_id'],
                'target' => 'table:synthetic_records',
                'target_columns' => ['id'],
            ]],
            polymorphicReferences: [[
                'column' => 'source_id',
                'discriminator_column' => 'source_type',
                'nullable' => false,
                'cases' => [[
                    'base' => 0,
                    'equals' => 'synthetic',
                    'mapping' => 'tenant_id',
                    'multiplier' => 1,
                    'slots' => [],
                    'target' => 'table:synthetic_records',
                    'target_columns' => ['id'],
                    'transform' => 'identity',
                ]],
            ]],
        ));
        $payload = \MyInvoice\Service\Backup\CanonicalJson::encode([
            'person_id' => 17,
        ]);
        $kinds = [];

        $restored = $projection->remapReferences(
            [
                'id' => 3,
                'supplier_id' => 7,
                'external_reference' => 'employee:13',
                'source_type' => 'synthetic',
                'source_id' => 19,
                'payload_json' => $payload,
                'payload_hash' => hash('sha256', $payload),
            ],
            static function (
                CompanyBackupReferenceOccurrence $occurrence,
            ) use (&$kinds): CompanyBackupSourceKey {
                $kinds[] = $occurrence->sourceKind;
                $values = $occurrence->sourceKey;
                foreach ($values as $column => $value) {
                    $values[$column] = $occurrence->targetRegistryKey === 'table:supplier'
                        ? 71
                        : (int) $value + 100;
                }
                return CompanyBackupSourceKey::fromValues(
                    $occurrence->targetRegistryKey,
                    $values,
                );
            },
        );

        self::assertSame(
            [
                CompanyBackupReferenceOccurrence::KIND_COLUMN,
                CompanyBackupReferenceOccurrence::KIND_ENCODED,
                CompanyBackupReferenceOccurrence::KIND_EMBEDDED,
                CompanyBackupReferenceOccurrence::KIND_POLYMORPHIC,
            ],
            $kinds,
        );
        self::assertSame(71, $restored['supplier_id']);
        self::assertSame('employee:113', $restored['external_reference']);
        self::assertSame(119, $restored['source_id']);
        self::assertSame(
            117,
            json_decode(
                (string) $restored['payload_json'],
                true,
                flags: JSON_THROW_ON_ERROR,
            )['person_id'],
        );
        self::assertSame(
            hash('sha256', (string) $restored['payload_json']),
            $restored['payload_hash'],
        );
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

    public function testRequiresExplicitCodecForEveryBinaryDataColumn(): void
    {
        $projection = CompanyBackupTableProjection::fromDefinition($this->definition(
            columnCodecs: ['name' => 'binary_hex'],
        ));

        $projection->assertRuntimeSchema(
            ['id', 'supplier_id', 'name'],
            [],
            ['id'],
            ['name'],
        );
        self::assertSame(
            'binary_hex',
            $projection->columnCodecs['name']->value,
        );

        try {
            $projection->assertRuntimeSchema(
                ['id', 'supplier_id', 'name'],
                [],
                ['id'],
                [],
            );
            self::fail('Kodek nesmí zůstat deklarovaný pro textový runtime sloupec.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_binary_columns_mismatch', $e->errorCode);
        }

        try {
            CompanyBackupTableProjection::fromDefinition($this->definition())
                ->assertRuntimeSchema(
                    ['id', 'supplier_id', 'name'],
                    [],
                    ['id'],
                    ['name'],
                );
            self::fail('Binární data bez explicitního kodeku nesmějí do JSONL.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_binary_columns_mismatch', $e->errorCode);
        }
    }

    public function testRejectsUnknownColumnCodec(): void
    {
        try {
            CompanyBackupTableProjection::fromDefinition($this->definition(
                columnCodecs: ['name' => 'lossy_text'],
            ));
            self::fail('Neznámý sloupcový kodek nesmí změnit význam dat.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_projection_invalid', $e->errorCode);
            self::assertSame('name', $e->column);
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

    public function testBindsProtectedSecretToTargetMaterialization(): void
    {
        $projection = CompanyBackupTableProjection::fromDefinition($this->definition(
            secrets: [
                'bank_account_ciphertext' => [
                    'policy' => TenantSecretPolicy::ProtectedDomainSecret->value,
                    'storage' =>
                        CompanyBackupSecretStorage::ApplicationEncryptedContext->value,
                    'context' => 'payroll:{supplier_id}:{id}:bank_account',
                ],
            ],
            dataColumns: ['id', 'supplier_id', 'name'],
            omitColumns: [
                'bank_account_hash' => 'rederived_from_protected_secret',
                'bank_account_masked' => 'rederived_from_protected_secret',
            ],
            protectedSecretMaterializations: [[
                'entity_id_column' => 'id',
                'field' => 'bank_account',
                'materializer' => 'payroll_sensitive_v1',
                'nullable' => false,
                'secret_column' => 'bank_account_ciphertext',
                'target_columns' => [
                    'ciphertext' => 'bank_account_ciphertext',
                    'lookup_hash' => 'bank_account_hash',
                    'masked' => 'bank_account_masked',
                ],
                'tenant_id_column' => 'supplier_id',
            ]],
        ));

        self::assertSame(
            'bank_account_ciphertext<-payroll_sensitive_v1:bank_account'
                . '@supplier_id,id'
                . '->bank_account_ciphertext,bank_account_hash,bank_account_masked',
            $projection->protectedSecretMaterializations
                ->materializations[0]
                ->signature(),
        );
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
     * @param array<string,string> $columnCodecs
     * @param list<array<string,mixed>> $encodedReferences
     * @param list<array<string,mixed>> $derivedHashes
     * @param list<array<string,mixed>> $embeddedHashes
     * @param list<array<string,mixed>> $embeddedHashReferences
     * @param list<array<string,mixed>> $embeddedReferences
     * @param list<array<string,mixed>> $protectedSecretMaterializations
     */
    private function definition(
        array $secrets = [],
        array $dataColumns = ['id', 'supplier_id', 'name'],
        array $generatedColumns = [],
        array $omitColumns = [],
        ?array $references = null,
        array $polymorphicReferences = [],
        array $preservedIdentifiers = [],
        array $columnCodecs = [],
        array $encodedReferences = [],
        array $derivedHashes = [],
        array $embeddedHashes = [],
        array $embeddedHashReferences = [],
        array $embeddedReferences = [],
        array $protectedSecretMaterializations = [],
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
                    ...($columnCodecs === [] ? [] : [
                        'column_codecs' => $columnCodecs,
                    ]),
                    ...($derivedHashes === [] ? [] : [
                        'derived_hashes' => $derivedHashes,
                    ]),
                    ...($encodedReferences === [] ? [] : [
                        'encoded_references' => $encodedReferences,
                    ]),
                    ...($embeddedHashes === [] ? [] : [
                        'embedded_hashes' => $embeddedHashes,
                    ]),
                    ...($embeddedHashReferences === [] ? [] : [
                        'embedded_hash_references' => $embeddedHashReferences,
                    ]),
                    'embedded_references' => $embeddedReferences,
                    'generated_columns' => $generatedColumns,
                    'omit_columns' => $omitColumns,
                    ...($polymorphicReferences === [] ? [] : [
                        'polymorphic_references' => $polymorphicReferences,
                    ]),
                    ...($preservedIdentifiers === [] ? [] : [
                        'preserved_identifiers' => $preservedIdentifiers,
                    ]),
                    ...($protectedSecretMaterializations === [] ? [] : [
                        'protected_secret_materializations' =>
                            $protectedSecretMaterializations,
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
