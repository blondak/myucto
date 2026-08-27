<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PHPUnit\Framework\TestCase;

final class CompanyBackupReferenceSetTest extends TestCase
{
    public function testAcceptsRequiredTenantFkAndNullableActorMapping(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [
                $this->actorReference(),
                $this->supplierReference(),
            ],
            'table:accounting_periods',
        );

        $references->assertProjectionColumns(['id', 'supplier_id', 'approved_by']);
        $references->assertRegistryTargets($this->targetRegistry());
        $references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['approved_by'],
            [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
        ));

        self::assertCount(2, $references->references);
        self::assertSame(
            CompanyBackupReferenceMapping::Actor,
            $references->references[0]->mapping,
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Required,
            $references->references[1]->constraint,
        );
    }

    public function testRejectsReferenceLikeColumnWithoutMapping(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [$this->supplierReference()],
            'table:synthetic_records',
        );

        try {
            $references->assertProjectionColumns(['id', 'supplier_id', 'source_id']);
            self::fail('Každý exportovaný *_id musí mít explicitní remap politiku.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_column_unclassified', $e->errorCode);
            self::assertSame('source_id', $e->column);
        }
    }

    public function testRejectsUnknownRuntimeForeignKey(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [$this->supplierReference()],
            'table:synthetic_records',
        );
        $references->assertProjectionColumns(['id', 'supplier_id', 'legacy_owner']);

        try {
            $references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
                [],
                [
                    new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
                    new CompanyBackupForeignKey(['legacy_owner'], 'users', ['id']),
                ],
            ));
            self::fail('Skutečný FK bez klasifikace nesmí projít coverage branou.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_foreign_key_unclassified', $e->errorCode);
            self::assertSame('legacy_owner', $e->column);
        }
    }

    public function testRejectsMissingRequiredConstraintAndNullabilityDrift(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [$this->supplierReference()],
            'table:synthetic_records',
        );

        try {
            $references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema([], []));
            self::fail('Povinný fyzický FK nesmí ze schématu tiše zmizet.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_constraint_missing', $e->errorCode);
            self::assertSame('supplier_id', $e->column);
        }

        try {
            $references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
                ['supplier_id'],
                [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
            ));
            self::fail('Změna nullability reference musí změnit její obnovovací politiku.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_nullability_mismatch', $e->errorCode);
            self::assertSame('supplier_id', $e->column);
        }
    }

    public function testRejectsActorMappingToTenantTable(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [$this->actorReference()],
            'table:accounting_periods',
        );
        $registry = new TenantDataRegistry(
            1,
            [$this->definition('users', TenantDataPolicy::TenantOwned)],
        );

        try {
            $references->assertRegistryTargets($registry);
            self::fail('Actor se smí mapovat jen na instanční uživatelský účet.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_target_invalid', $e->errorCode);
            self::assertSame('approved_by', $e->column);
        }
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

    /** @return array<string,mixed> */
    private function actorReference(): array
    {
        return [
            'columns' => ['approved_by'],
            'target' => 'table:users',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::Actor->value,
            'constraint' => CompanyBackupReferenceConstraint::Optional->value,
            'nullable_columns' => ['approved_by'],
            'fallbacks' => ['null', 'restore_actor'],
        ];
    }

    private function targetRegistry(): TenantDataRegistry
    {
        return new TenantDataRegistry(
            1,
            [
                $this->definition('supplier', TenantDataPolicy::TenantRoot),
                $this->definition('users', TenantDataPolicy::InstanceOwned),
            ],
        );
    }

    private function definition(string $table, TenantDataPolicy $policy): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            ['primary_key' => ['id']],
        );
    }
}
