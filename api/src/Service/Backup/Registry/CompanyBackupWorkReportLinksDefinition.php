<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinksProjection;

/** Kontrakt odkazů v neúplném company profilu; projektová vazba platí už při INSERT. */
final class CompanyBackupWorkReportLinksDefinition
{
    public static function definition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:work_report_links',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'core',
                'ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                'secrets' => CompanyBackupWorkReportLinksProjection::secrets(),
                'company_backup' => [
                    'data_columns' => CompanyBackupWorkReportLinksProjection::dataColumns(),
                    'deferred_updates' => false,
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'protected_secret_materializations' =>
                        CompanyBackupWorkReportLinksProjection::protectedSecretMaterializations(),
                    'references' => CompanyBackupWorkReportLinksProjection::references(),
                    'restore_overrides' => [],
                ],
            ],
        );
    }
}
