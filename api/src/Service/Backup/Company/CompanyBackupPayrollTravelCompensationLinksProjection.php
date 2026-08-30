<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce vazeb mzdových vstupů na cestovní náhrady.
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
final class CompanyBackupPayrollTravelCompensationLinksProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'input_id',
            'trip_id',
            'source_system',
            'source_reference',
            'classification_status',
            'created_at',
        ];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            [
                'columns' => ['supplier_id', 'input_id'],
                'target' => 'table:payroll_inputs',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id', 'trip_id'],
                'target' => 'table:payroll_business_trips',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['trip_id'],
                'fallbacks' => [],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function encodedReferences(): array
    {
        return [[
            'column' => 'source_reference',
            'condition' => [
                'column' => 'source_system',
                'equals' => 'payroll_business_trip',
            ],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'nullable' => false,
            'target' => 'table:payroll_business_trips',
            'target_columns' => ['id'],
            'value_prefix' => 'trip:',
            'value_suffix_separator' => ':',
        ]];
    }
}
