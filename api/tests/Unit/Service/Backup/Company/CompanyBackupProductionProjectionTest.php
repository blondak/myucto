<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
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

    public function testRemainingProductionTableStillFailsClosedWithoutInventory(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:accounting_periods');
        self::assertNotNull($definition);

        try {
            CompanyBackupTableProjection::fromDefinition($definition);
            self::fail('Dílčí inventura nesmí implicitně otevřít ostatní tabulky profilu.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_projection_missing', $e->errorCode);
        }
    }
}
