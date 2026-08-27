<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupProductionProjectionTest extends TestCase
{
    public function testCurrenciesHaveFirstExplicitProductionColumnProjection(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:currencies');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'code',
            'label',
            'symbol',
            'name_cs',
            'name_en',
            'decimals',
            'is_active',
            'is_default',
            'account_number',
            'bank_code',
            'bank_name',
            'iban',
            'bic',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame([], $projection->generatedColumns);
        self::assertSame([], $projection->omitColumns);
        self::assertNull($projection->requiredSecretEnvelopeColumn());
        self::assertCount(1, $projection->references->references);
        self::assertSame(
            CompanyBackupReferenceMapping::TenantId,
            $projection->references->references[0]->mapping,
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Required,
            $projection->references->references[0]->constraint,
        );
        $projection->references->assertRegistryTargets(TenantDataRegistryFactory::draftV1());
    }

    public function testAccountingPeriodsDeclareNullableHistoricalActors(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:accounting_periods');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'fiscal_year',
            'starts_on',
            'ends_on',
            'status',
            'closed_at',
            'row_version',
            'closed_by',
            'approved_at',
            'approved_by',
            'reviewed_at',
            'reviewed_by',
            'approval_body',
            'approval_decision_ref',
            'approval_document_hash',
            'created_at',
            'small_asset_accrual_mode',
            'small_asset_accrual_pct',
            'small_asset_flat_pct_materiality_limit',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['approved_by', 'closed_by', 'reviewed_by'],
            [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            ['approved_by', 'closed_by', 'reviewed_by', 'supplier_id'],
            array_map(
                static fn ($reference): string => $reference->firstColumn(),
                $projection->references->references,
            ),
        );
        foreach (array_slice($projection->references->references, 0, 3) as $actor) {
            self::assertSame(CompanyBackupReferenceMapping::Actor, $actor->mapping);
            self::assertSame(['null', 'restore_actor'], $actor->fallbacks);
        }

        $users = $registry->definition('table:users');
        self::assertNotNull($users);
        self::assertSame(TenantDataPolicy::InstanceOwned, $users->policy);
        self::assertFalse($users->policy->hasMachineDataPayload());
    }

    public function testRemainingProductionTableStillFailsClosedWithoutInventory(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:chart_of_accounts');
        self::assertNotNull($definition);

        try {
            CompanyBackupTableProjection::fromDefinition($definition);
            self::fail('Dílčí inventura nesmí implicitně otevřít ostatní tabulky profilu.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_projection_missing', $e->errorCode);
        }
    }
}
