<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce výběru podpisového profilu po dokumentech. */
final class CompanyBackupSignatureDocumentOverridesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'supplier_id',
            'usage',
            'entity_type',
            'entity_id',
            'selection_source',
            'admin_profile_id',
            'created_by',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return list<array{
     *   columns:list<string>,
     *   target:string,
     *   target_columns:list<string>,
     *   mapping:string,
     *   constraint:string,
     *   nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }>
     */
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
                'columns' => ['supplier_id', 'admin_profile_id'],
                'target' => 'table:signing_profiles',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['admin_profile_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id'],
                'target' => 'table:supplier',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function polymorphicReferences(): array
    {
        return [[
            'column' => 'entity_id',
            'discriminator_column' => 'entity_type',
            'nullable' => false,
            'cases' => [
                self::invoiceCase('invoice'),
                self::invoiceCase('work_report'),
            ],
        ]];
    }

    /** @return array<string,mixed> */
    private static function invoiceCase(string $entityType): array
    {
        return [
            'base' => 0,
            'equals' => $entityType,
            'mapping' => CompanyBackupPolymorphicReferenceMapping::TenantId->value,
            'multiplier' => 1,
            'slots' => [],
            'target' => 'table:invoices',
            'target_columns' => ['id'],
            'transform' => CompanyBackupPolymorphicReferenceTransform::Identity->value,
        ];
    }
}
