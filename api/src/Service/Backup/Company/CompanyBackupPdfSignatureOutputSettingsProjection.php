<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce pravidel podpisu jednotlivých výstupů. */
final class CompanyBackupPdfSignatureOutputSettingsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'supplier_id',
            'usage',
            'output_type',
            'enabled',
            'backend',
            'selection_source',
            'user_profile_fallback',
            'default_profile_id',
            'failure_policy',
            'signature_config_json',
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
                'columns' => ['supplier_id', 'default_profile_id'],
                'target' => 'table:signing_profiles',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['default_profile_id'],
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
}
