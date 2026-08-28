<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce firemních přepínačů podpisových profilů. */
final class CompanyBackupSigningSettingsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'supplier_id',
            'accountant_profiles_enabled',
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
        return [[
            'columns' => ['supplier_id'],
            'target' => 'table:supplier',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ]];
    }
}
