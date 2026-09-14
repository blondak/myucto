<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

use MyInvoice\Service\Backup\Company\CompanyBackupSubmissionRecipientsProjection;

/** Vlastní příjemci se obnovují; použité systémové řádky se mapují na cílový katalog. */
final class CompanyBackupSubmissionRecipientsDefinition
{
    public static function definition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            CompanyBackupSubmissionRecipientsProjection::REGISTRY_KEY,
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'natural_key' => ['code'],
                'feature_group' => 'integrations',
                'ownership' => ['strategy' => 'submission_recipient_scope'],
                'secrets' => [],
                'company_backup' => [
                    'data_columns' => CompanyBackupSubmissionRecipientsProjection::dataColumns(),
                    'deferred_updates' => false,
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'embedded_references' => [],
                    'preserved_identifiers' => ['business_id', 'isds_box_id'],
                    'restore_overrides' => [],
                    'references' => CompanyBackupSubmissionRecipientsProjection::references(),
                ],
            ],
        );
    }
}
