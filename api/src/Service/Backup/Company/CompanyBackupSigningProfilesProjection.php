<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce podpisových profilů bez TSA credentials. */
final class CompanyBackupSigningProfilesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'owner_user_id',
            'name',
            'code',
            'allowed_usages_json',
            'default_backend',
            'pdf_tsa_url',
            'pdf_tsa_username',
            'pdf_reason',
            'is_active',
            'created_by',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }

    /**
     * Osobní vlastník nesmí při chybějícím uživateli přejít na NULL: takový
     * profil by se stal firemním. Restore actor zachová osobní hranici, zatímco
     * historický autor může bezpečně zůstat neznámý.
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
                'columns' => ['created_by'],
                'target' => 'table:users',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::Actor->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['created_by'],
                'fallbacks' => ['null', 'restore_actor'],
            ],
            [
                'columns' => ['owner_user_id'],
                'target' => 'table:users',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::Actor->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['owner_user_id'],
                'fallbacks' => ['restore_actor'],
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
