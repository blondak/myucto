<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupSourceKey;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPrimaryKeySeedTest extends TestCase
{
    public function testCompositeForeignKeyStaysInSourceCoordinatesUntilRemap(): void
    {
        $projection = $this->projection('tax_profile_child_months');
        $source = ['child_id' => 7, 'month' => 2, 'child_order' => 2, 'ztpp' => 1, 'claimed' => 0];
        $seed = $projection->seedPreallocatedPrimaryKey($source,
            CompanyBackupSourceKey::fromValues($projection->registryKey, ['child_id' => 107, 'month' => 2]));
        self::assertSame($source, $seed);
        $mapped = $projection->references->remap($seed,
            static function ($reference, array $values): array {
                self::assertSame([7], $values);
                return [107];
            });
        self::assertSame(array_replace($source, ['child_id' => 107]), $mapped);
    }

    public function testOwnReservedPrimaryKeyIsAvailableBeforeOtherTransformations(): void
    {
        $projection = $this->projection('tax_profile_children');
        $source = ['id' => 7, 'supplier_id' => 11, 'year' => 2021];
        self::assertSame(['id' => 107, 'supplier_id' => 11, 'year' => 2021],
            $projection->seedPreallocatedPrimaryKey($source,
                CompanyBackupSourceKey::fromValues($projection->registryKey, ['id' => 107])));
    }

    public function testRejectsForeignPrimaryKey(): void
    {
        $this->expectException(\LogicException::class);
        $this->projection('tax_profile_children')->seedPreallocatedPrimaryKey(['id' => 7],
            CompanyBackupSourceKey::fromValues('table:supplier', ['id' => 107]));
    }

    private function projection(string $table): CompanyBackupTableProjection
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:' . $table);
        self::assertNotNull($definition);
        return CompanyBackupTableProjection::fromDefinition($definition);
    }
}
