<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

use MyInvoice\Service\Backup\Company\CompanyBackupTaxSubmissionSummaryContract;

/** Neaktivní kontrakt archivu: nejdřív musí být ověřeny všechny podporované souhrny. */
final class CompanyBackupTaxSubmissionsDefinition
{
    public static function definition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:tax_submissions',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'accounting',
                'ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                'secrets' => [],
                'company_backup' => [
                    'data_columns' => [
                        'id', 'supplier_id', 'form_code', 'period_year',
                        'period_month', 'period_quarter', 'form_variant',
                        'xml_content', 'xml_size_bytes', 'xml_sha256',
                        'validation_status', 'status', 'submitted_at',
                        'submission_ref', 'submitted_by', 'validation_errors',
                        'summary_json', 'generated_by', 'generated_at', 'notes',
                    ],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'restore_overrides' => [],
                    'embedded_references' => CompanyBackupTaxSubmissionSummaryContract::embeddedReferences(),
                    'references' => [
                        self::actor('generated_by'),
                        self::actor('submitted_by'),
                        [
                            'columns' => ['supplier_id'], 'target' => 'table:supplier',
                            'target_columns' => ['id'], 'mapping' => 'tenant_id',
                            'constraint' => 'optional', 'nullable_columns' => [],
                            'fallbacks' => [],
                        ],
                    ],
                ],
            ],
        );
    }

    /** @return array<string,mixed> */
    private static function actor(string $column): array
    {
        // Archiv eviduje uživatele logicky; migrace nezakládají fyzický FK.
        return [
            'columns' => [$column], 'target' => 'table:users',
            'target_columns' => ['id'], 'mapping' => 'actor',
            'constraint' => 'optional', 'nullable_columns' => [$column],
            'fallbacks' => ['null', 'restore_actor'],
        ];
    }
}
