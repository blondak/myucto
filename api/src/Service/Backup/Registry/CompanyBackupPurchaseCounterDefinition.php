<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

/** Čítač může být vyšší než maximum dochovaných dokladů; nepřepočítává se. */
final class CompanyBackupPurchaseCounterDefinition
{
    public static function definition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:purchase_invoice_counters', TenantDataObjectKind::Table, TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['supplier_id', 'period'],
                'feature_group' => 'purchase_invoices',
                'ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                'secrets' => [],
                'company_backup' => [
                    'data_columns' => ['supplier_id', 'period', 'last_number'],
                    'generated_columns' => [], 'omit_columns' => [],
                    'embedded_references' => [], 'restore_overrides' => [],
                    'references' => [[
                        'columns' => ['supplier_id'], 'target' => 'table:supplier',
                        'target_columns' => ['id'], 'mapping' => 'tenant_id',
                        'constraint' => 'required', 'nullable_columns' => [], 'fallbacks' => [],
                    ]],
                ],
            ],
        );
    }
}
