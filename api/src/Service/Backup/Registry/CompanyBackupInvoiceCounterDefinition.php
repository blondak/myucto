<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

use MyInvoice\Service\Backup\Company\CompanyBackupInvoiceCounterKey;

final class CompanyBackupInvoiceCounterDefinition
{
    public static function definition(): TenantDataDefinition
    {
        $references = [];
        foreach (['client_id' => 'clients', 'revenue_category_id' => 'revenue_categories', 'supplier_id' => 'supplier'] as $column => $table) {
            $references[] = [
                'columns' => [$column], 'target' => 'table:' . $table, 'target_columns' => ['id'],
                'mapping' => $column === 'supplier_id' ? 'tenant_id' : 'tenant_id_or_zero',
                'constraint' => $column === 'supplier_id' ? 'required' : 'optional',
                'nullable_columns' => [], 'fallbacks' => [],
            ];
        }
        return new TenantDataDefinition(CompanyBackupInvoiceCounterKey::REGISTRY_KEY,
            TenantDataObjectKind::Table, TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE], [
                'primary_key' => CompanyBackupInvoiceCounterKey::COLUMNS,
                'feature_group' => 'invoices',
                'ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id'], 'secrets' => [],
                'company_backup' => [
                    'data_columns' => [...CompanyBackupInvoiceCounterKey::COLUMNS, 'last_number'],
                    'generated_columns' => [], 'omit_columns' => [], 'embedded_references' => [],
                    'restore_overrides' => [], 'references' => $references,
                ],
            ]);
    }
}
