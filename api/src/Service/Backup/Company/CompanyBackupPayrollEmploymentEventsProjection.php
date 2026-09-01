<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce historie pracovního vztahu.
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
final class CompanyBackupPayrollEmploymentEventsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'employment_id',
            'event_type',
            'from_status',
            'to_status',
            'effective_on',
            'note',
            'diff_json',
            'created_by',
            'created_at',
        ];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            [
                'columns' => ['created_by'],
                'target' => 'table:users',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::Actor->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['created_by'],
                'fallbacks' => ['null', 'restore_actor'],
            ],
            [
                'columns' => ['supplier_id', 'employment_id'],
                'target' => 'table:payroll_employments',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function embeddedReferences(): array
    {
        return [
            self::officeReference('from'),
            self::officeReference('to'),
        ];
    }

    /** @return array<string,mixed> */
    private static function officeReference(string $side): array
    {
        return [
            'column' => 'diff_json',
            'condition' => null,
            'fallbacks' => [],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'nullable' => true,
            'path' => ['office_id', $side],
            'target' => 'table:payroll_offices',
            'target_columns' => ['id'],
        ];
    }
}
