<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

/** Hostname je globálně unikátní bezpečnostní hranice, nikoli importovatelná vazba. */
final class CompanyBackupSupplierDomainsDefinition
{
    public static function definition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:supplier_domains', TenantDataObjectKind::Table,
            TenantDataPolicy::ManualConfiguration,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'identity',
                'ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                'secrets' => ['verification_token' => [
                    'policy' => TenantSecretPolicy::OmitAndReconfigure->value,
                ]],
                'company_backup' => [
                    'data_columns' => ['id', 'supplier_id', 'hostname', 'purpose',
                        'is_primary_portal', 'is_primary_public'],
                    'generated_columns' => ['is_primary', 'primary_portal_supplier_id',
                        'primary_public_supplier_id'],
                    'omit_columns' => [
                        'status' => 'domain_requires_fresh_verification',
                        'verified_at' => 'domain_requires_fresh_verification',
                        'last_checked_at' => 'instance_domain_verification_state',
                        'verification_error' => 'instance_domain_verification_state',
                        'created_by' => 'instance_domain_binding_actor',
                        'updated_by' => 'instance_domain_binding_actor',
                        'created_at' => 'instance_domain_binding_timestamp',
                        'updated_at' => 'instance_domain_binding_timestamp',
                    ],
                    'embedded_references' => [],
                    'references' => [[
                        'columns' => ['supplier_id'], 'target' => 'table:supplier',
                        'target_columns' => ['id'], 'mapping' => 'tenant_id',
                        'constraint' => 'required', 'nullable_columns' => [], 'fallbacks' => [],
                    ]],
                    'restore_overrides' => [],
                ],
            ],
        );
    }
}
