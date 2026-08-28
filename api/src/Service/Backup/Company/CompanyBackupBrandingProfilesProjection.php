<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce obchodních identit a jejich dodavatelských log. */
final class CompanyBackupBrandingProfilesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'name',
            'display_name',
            'tagline',
            'email',
            'reply_to',
            'email_profile_id',
            'phone',
            'web',
            'email_footer',
            'logo_path',
            'accent_color',
            'branding_enabled',
            'pdf_logo_show_name',
            'is_active',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * Odesílací profil se přenáší jako samostatný tenantový objekt. Jeho
     * credentials a aktivní stav mají vlastní bezpečnostní politiku; reference
     * zde proto nesmí být zaměněna za externí nebo instanční identitu.
     *
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
                'columns' => ['supplier_id', 'email_profile_id'],
                'target' => 'table:email_profiles',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['email_profile_id'],
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
