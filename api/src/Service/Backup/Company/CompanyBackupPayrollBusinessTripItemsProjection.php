<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce výdajových položek pracovních cest.
 *
 * @phpstan-type TableReference array{
 *   columns:list<string>,
 *   target:string,
 *   target_columns:list<string>,
 *   mapping:string,
 *   constraint:string,
 *   nullable_columns:list<string>,
 *   fallbacks:list<string>
 * }
 */
final class CompanyBackupPayrollBusinessTripItemsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'trip_id',
            'item_kind',
            'spent_on',
            'description',
            'amount_minor',
            'is_documented',
            'document_reference',
            'vehicle_kind',
            'distance_m',
            'consumption_ml_per_100km',
            'fuel_kind',
            'documented_fuel_price_minor',
            'sort_order',
            'created_at',
        ];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [[
            'columns' => ['supplier_id', 'trip_id'],
            'target' => 'table:payroll_business_trips',
            'target_columns' => ['supplier_id', 'id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ]];
    }
}
