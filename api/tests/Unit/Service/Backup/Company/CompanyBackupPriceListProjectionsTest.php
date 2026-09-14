<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupPriceListCustomerOverridesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPriceListItemPricesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPriceListItemsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPriceListProjectionsTest extends TestCase
{
    /**
     * @return array<string,array{class-string,list<string>,list<string>,list<string>}>
     */
    public static function tables(): array
    {
        return [
            'price_list_items' => [
                CompanyBackupPriceListItemsProjection::class,
                [
                    'id', 'supplier_id', 'code', 'name', 'description', 'unit',
                    'vat_rate_id', 'prices_include_vat', 'base_currency_code',
                    'allow_exchange_rate_conversion', 'archived', 'created_at',
                    'updated_at',
                ],
                ['supplier_id->supplier:id', 'vat_rate_id->vat_rates:id'],
                ['supplier_id', 'vat_rate_id'],
            ],
            'price_list_item_prices' => [
                CompanyBackupPriceListItemPricesProjection::class,
                [
                    'id', 'supplier_id', 'price_list_item_id', 'currency_code',
                    'unit_price', 'archived', 'created_at', 'updated_at',
                ],
                ['price_list_item_id->price_list_items:id', 'supplier_id->supplier:id'],
                ['price_list_item_id', 'supplier_id'],
            ],
            'price_list_customer_overrides' => [
                CompanyBackupPriceListCustomerOverridesProjection::class,
                [
                    'id', 'supplier_id', 'price_list_item_id', 'client_id',
                    'currency_code', 'unit_price', 'created_at', 'updated_at',
                ],
                [
                    'client_id->clients:id',
                    'price_list_item_id->price_list_items:id',
                    'supplier_id->supplier:id',
                ],
                ['client_id', 'price_list_item_id', 'supplier_id'],
            ],
        ];
    }

    /**
     * @param class-string $projectionClass
     * @param list<string> $columns
     * @param list<string> $signatures
     * @param list<string> $mappedColumns
     */
    #[DataProvider('tables')]
    public function testCompletePhysicalContractAndExactSavedValues(
        string $projectionClass,
        array $columns,
        array $signatures,
        array $mappedColumns,
    ): void {
        self::assertSame($columns, $projectionClass::dataColumns());
        $refs = CompanyBackupReferenceSet::fromArray(
            $projectionClass::references(), 'table:synthetic_price_list',
        );
        self::assertSame($signatures, array_map(
            static fn (CompanyBackupReference $reference): string =>
                $reference->signature(),
            $refs->references,
        ));
        foreach ($refs->references as $reference) {
            self::assertSame(CompanyBackupReferenceConstraint::Required,
                $reference->constraint);
            self::assertSame([], $reference->nullableColumns);
            self::assertSame([], $reference->fallbacks);
            self::assertSame(
                $reference->firstColumn() === 'vat_rate_id'
                    ? CompanyBackupReferenceMapping::GlobalNaturalKey
                    : CompanyBackupReferenceMapping::TenantId,
                $reference->mapping,
            );
        }

        $row = array_fill_keys($columns, 'historical');
        $row['id'] = 5;
        foreach ($mappedColumns as $index => $column) {
            $row[$column] = $index + 10;
        }
        foreach (['unit_price', 'prices_include_vat', 'archived',
            'allow_exchange_rate_conversion'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = $column === 'unit_price' ? '123.45' : 1;
            }
        }
        foreach (['currency_code', 'base_currency_code'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = 'CZK';
            }
        }
        $original = $row;
        $mapped = $refs->remap($row,
            static function (CompanyBackupReference $reference, array $key): array {
                self::assertIsInt($key[0]);
                return [$key[0] + 100];
            },
        );
        foreach ($original as $column => $value) {
            $expected = $value;
            if (in_array($column, $mappedColumns, true)) {
                self::assertIsInt($value);
                $expected = $value + 100;
            }
            self::assertSame(
                $expected, $mapped[$column], $column,
            );
        }
        self::assertSame($original, $row);
    }
}
