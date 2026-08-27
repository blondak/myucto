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
     */
    private function definition(
        array $secrets = [],
        array $dataColumns = ['id', 'supplier_id', 'name'],
        array $generatedColumns = [],
        array $omitColumns = [],
        ?array $references = null,
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
                    'generated_columns' => $generatedColumns,
                    'omit_columns' => $omitColumns,
                    'references' => $references ?? [$this->supplierReference()],
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
