<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

use MyInvoice\Service\Backup\Company\CompanyBackupSubmissionOutboxProjection;

/** Zatím neaktivní kontrakt: příjemce, artefakty a doručenky tvoří jeden graf. */
final class CompanyBackupSubmissionOutboxDefinition
{
    public static function definition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            CompanyBackupSubmissionOutboxProjection::REGISTRY_KEY,
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'integrations',
                'ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                'secrets' => [],
                'company_backup' => [
                    'data_columns' => CompanyBackupSubmissionOutboxProjection::dataColumns(),
                    'column_codecs' => CompanyBackupSubmissionOutboxProjection::columnCodecs(),
                    'deferred_updates' => false,
                    'derived_hashes' => CompanyBackupSubmissionOutboxProjection::derivedHashes(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'polymorphic_references' => CompanyBackupSubmissionOutboxProjection::polymorphicReferences(),
                    'preserved_identifiers' => CompanyBackupSubmissionOutboxProjection::preservedIdentifiers(),
                    'references' => CompanyBackupSubmissionOutboxProjection::references(),
                    'restore_overrides' => [],
                ],
            ],
        );
    }
}
