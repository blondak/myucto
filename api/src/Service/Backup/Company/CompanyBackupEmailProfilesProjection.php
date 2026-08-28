<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce odesílacích profilů bez přenosu credentials. */
final class CompanyBackupEmailProfilesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'name',
            'code',
            'from_email',
            'from_name',
            'reply_to_email',
            'reply_to_name',
            'reply_to_enabled',
            'signing_profile_id',
            'dkim_domain',
            'dkim_selector',
            'dkim_enabled',
            'transport_type',
            'smtp_host',
            'smtp_port',
            'smtp_encryption',
            'smtp_auth_enabled',
            'smtp_auth_type',
            'smtp_username',
            'smtp_verify_peer',
            'smtp_verify_peer_name',
            'smtp_allow_self_signed',
            'smtp_timeout',
            'smtp_keepalive',
            'sendmail_command',
            'imap_sent_enabled',
            'imap_host',
            'imap_port',
            'imap_encryption',
            'imap_validate_cert',
            'imap_username',
            'imap_folder',
            'imap_create_folder',
            'imap_mark_seen',
            'imap_timeout',
            'imap_on_failure',
            'is_default',
            'is_active',
            'created_by',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }

    /**
     * Podpisový profil je tenantová konfigurace, zatímco autor je historická
     * instanční identita s explicitním fallbackem. Aktivaci dopravy řeší
     * fingerprintovaný restore override, nikoli odlišná reference.
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
                'columns' => ['signing_profile_id'],
                'target' => 'table:signing_profiles',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['signing_profile_id'],
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
