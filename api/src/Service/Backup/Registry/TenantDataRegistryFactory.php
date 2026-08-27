<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

/** Produkční sestavení registru; company_backup zůstává během inventury draft. */
final class TenantDataRegistryFactory
{
    /** @var list<string> */
    private const ACCOUNTING_ARCHIVE_EXPORT_ORDER = [
        'supplier',
        'accounting_periods',
        'chart_of_accounts',
        'posting_rules',
        'cost_centers',
        'accounting_supplier_settings',
        'accounting_closing_steps',
        'accounting_document_series',
        'journal_entries',
        'journal_entry_lines',
        'clients',
        'client_bank_accounts',
        'currencies',
        'invoices',
        'purchase_invoices',
        'assets',
        'asset_improvements',
        'depreciation_entries',
        'payment_matches',
        'cash_registers',
        'cash_documents',
        'invoice_payments',
        'income_tax_returns',
        'tax_losses',
        'tax_loss_applications',
        'tax_advance_schedules',
        'journal_entry_attachments',
        'cash_document_vat_lines',
        'warehouses',
        'stock_items',
        'manufacturers',
        'stock_media',
        'stock_categories',
        'stock_category_i18n',
        'stock_item_categories',
        'stock_tags',
        'stock_item_tags',
        'stock_attributes',
        'stock_attribute_options',
        'stock_attribute_i18n',
        'stock_item_attribute_values',
        'stock_fee_types',
        'stock_item_fees',
        'stock_item_prices',
        'stock_item_vendors',
        'stock_item_i18n',
        'stock_levels',
        'stock_documents',
        'stock_document_lines',
        'stock_landed_costs',
        'stock_takes',
        'stock_take_lines',
        'invoice_items',
        'purchase_invoice_items',
        'bank_transactions',
        'bank_statements',
        'exchange_rates',
    ];

    /** @var list<string> */
    private const ACCOUNTING_ARCHIVE_RESTORE_ORDER = [
        'supplier',
        'accounting_periods',
        'chart_of_accounts',
        'currencies',
        'clients',
        'posting_rules',
        'cost_centers',
        'accounting_supplier_settings',
        'accounting_document_series',
        'invoices',
        'invoice_items',
        'purchase_invoices',
        'purchase_invoice_items',
        'assets',
        'asset_improvements',
        'depreciation_entries',
        'bank_statements',
        'bank_transactions',
        'client_bank_accounts',
        'journal_entries',
        'journal_entry_lines',
        'accounting_closing_steps',
        'payment_matches',
        'cash_registers',
        'cash_documents',
        'cash_document_vat_lines',
        'invoice_payments',
        'income_tax_returns',
        'tax_losses',
        'tax_loss_applications',
        'tax_advance_schedules',
        'warehouses',
        'stock_items',
        'manufacturers',
        'stock_media',
        'stock_categories',
        'stock_category_i18n',
        'stock_item_categories',
        'stock_tags',
        'stock_item_tags',
        'stock_attributes',
        'stock_attribute_options',
        'stock_attribute_i18n',
        'stock_item_attribute_values',
        'stock_fee_types',
        'stock_item_fees',
        'stock_item_prices',
        'stock_item_vendors',
        'stock_item_i18n',
        'stock_levels',
        'stock_documents',
        'stock_document_lines',
        'stock_landed_costs',
        'stock_takes',
        'stock_take_lines',
        'journal_entry_attachments',
        'exchange_rates',
    ];

    /** @var array<string,list<string>> */
    private const PRIMARY_KEYS = [
        'accounting_supplier_settings' => ['supplier_id'],
        'stock_item_categories' => ['stock_item_id', 'category_id'],
        'stock_item_tags' => ['stock_item_id', 'tag_id'],
        'stock_levels' => ['supplier_id', 'warehouse_id', 'stock_item_id'],
        'exchange_rates' => ['rate_date', 'currency_code'],
    ];

    /** @var list<string> */
    private const STOCK_TABLES = [
        'warehouses',
        'stock_items',
        'manufacturers',
        'stock_media',
        'stock_categories',
        'stock_category_i18n',
        'stock_item_categories',
        'stock_tags',
        'stock_item_tags',
        'stock_attributes',
        'stock_attribute_options',
        'stock_attribute_i18n',
        'stock_item_attribute_values',
        'stock_fee_types',
        'stock_item_fees',
        'stock_item_prices',
        'stock_item_vendors',
        'stock_item_i18n',
        'stock_levels',
        'stock_documents',
        'stock_document_lines',
        'stock_landed_costs',
        'stock_takes',
        'stock_take_lines',
    ];

    public static function draftV1(): TenantDataRegistry
    {
        self::assertArchiveOrders();
        $definitions = [];
        foreach (self::ACCOUNTING_ARCHIVE_EXPORT_ORDER as $exportIndex => $table) {
            $restoreIndex = array_search($table, self::ACCOUNTING_ARCHIVE_RESTORE_ORDER, true);
            if ($restoreIndex === false) {
                throw new \LogicException('Účetní archiv nemá úplné pořadí obnovy.');
            }
            [$policy, $ownership] = self::classification($table);
            $details = [
                'primary_key' => self::PRIMARY_KEYS[$table] ?? ['id'],
                'feature_group' => self::featureGroup($table),
                'ownership' => $ownership,
                'secrets' => self::secretPolicies($table),
                'accounting_archive' => [
                    'export_order' => $exportIndex + 1,
                    'restore_order' => $restoreIndex + 1,
                    'selector' => self::archiveSelector($table),
                    'omit_columns' => self::archiveOmissions($table),
                    'soft_references' => self::softReferences($table),
                    ...self::archiveFeatureFlag($table),
                ],
            ];
            if ($table === 'bank_statements') {
                $details['natural_key'] = ['file_hash'];
            } elseif ($table === 'exchange_rates') {
                $details['natural_key'] = ['rate_date', 'currency_code'];
            }
            $definitions[] = new TenantDataDefinition(
                'table:' . $table,
                TenantDataObjectKind::Table,
                $policy,
                [
                    TenantDataRegistry::COMPANY_BACKUP_PROFILE,
                    TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
                ],
                $details,
            );
        }

        return new TenantDataRegistry(
            1,
            $definitions,
            [TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE],
        );
    }

    private static function assertArchiveOrders(): void
    {
        $export = self::ACCOUNTING_ARCHIVE_EXPORT_ORDER;
        $restore = self::ACCOUNTING_ARCHIVE_RESTORE_ORDER;
        $unique = count(array_unique($export, SORT_STRING)) === count($export)
            && count(array_unique($restore, SORT_STRING)) === count($restore);
        $same = array_diff($export, $restore) === [] && array_diff($restore, $export) === [];
        if (!$unique || !$same) {
            throw new \LogicException(
                'Export a obnova účetního archivu nemají stejnou jednoznačnou sadu tabulek.',
            );
        }
    }

    /** @return array{TenantDataPolicy,array<string,mixed>} */
    private static function classification(string $table): array
    {
        return match ($table) {
            'supplier' => [
                TenantDataPolicy::TenantRoot,
                ['strategy' => 'selected_supplier', 'column' => 'id'],
            ],
            'invoice_items' => [
                TenantDataPolicy::TenantOwnedIndirect,
                self::foreignKeyPath('invoice_id', 'invoices'),
            ],
            'purchase_invoice_items' => [
                TenantDataPolicy::TenantOwnedIndirect,
                self::foreignKeyPath('purchase_invoice_id', 'purchase_invoices'),
            ],
            'cash_document_vat_lines' => [
                TenantDataPolicy::TenantOwnedIndirect,
                self::foreignKeyPath('cash_document_id', 'cash_documents'),
            ],
            'bank_transactions' => [
                TenantDataPolicy::TenantOwnedIndirect,
                ['strategy' => 'bank_transaction_relationships'],
            ],
            'bank_statements' => [
                TenantDataPolicy::GlobalReference,
                ['strategy' => 'bank_statement_relationships'],
            ],
            'exchange_rates' => [
                TenantDataPolicy::GlobalReference,
                ['strategy' => 'accounting_period_currency'],
            ],
            default => [
                TenantDataPolicy::TenantOwned,
                ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
            ],
        };
    }

    /** @return array{strategy:string,path:list<array{from_column:string,to_table:string,to_column:string}>} */
    private static function foreignKeyPath(string $column, string $parent): array
    {
        return [
            'strategy' => 'foreign_key_path',
            'path' => [
                ['from_column' => $column, 'to_table' => $parent, 'to_column' => 'id'],
                ['from_column' => 'supplier_id', 'to_table' => 'supplier', 'to_column' => 'id'],
            ],
        ];
    }

    private static function featureGroup(string $table): string
    {
        if (in_array($table, self::STOCK_TABLES, true)) {
            return 'stock';
        }
        return match ($table) {
            'bank_transactions', 'bank_statements' => 'bank',
            'income_tax_returns', 'tax_losses', 'tax_loss_applications',
            'tax_advance_schedules' => 'tax',
            'journal_entry_attachments' => 'documents',
            'supplier', 'clients', 'client_bank_accounts', 'currencies',
            'invoices', 'invoice_items', 'purchase_invoices',
            'purchase_invoice_items', 'invoice_payments', 'payment_matches' => 'core',
            default => 'accounting',
        };
    }

    private static function archiveSelector(string $table): string
    {
        return match ($table) {
            'bank_transactions' => 'bank_transaction_relationships',
            'bank_statements' => 'bank_statement_relationships',
            'exchange_rates' => 'accounting_period_currency',
            default => 'ownership',
        };
    }

    /** @return list<string> */
    private static function archiveOmissions(string $table): array
    {
        return $table === 'bank_statements' ? ['file_content', 'pdf_content'] : [];
    }

    /** @return array{feature_flag:string}|array{} */
    private static function archiveFeatureFlag(string $table): array
    {
        return in_array($table, self::STOCK_TABLES, true)
            ? ['feature_flag' => 'stock_enabled']
            : [];
    }

    /** @return array<string,string> */
    private static function softReferences(string $table): array
    {
        return match ($table) {
            'stock_documents' => [
                'stock_take_id' => 'stock_takes',
                'reversal_document_id' => 'stock_documents',
            ],
            'stock_takes' => [
                'receipt_document_id' => 'stock_documents',
                'issue_document_id' => 'stock_documents',
            ],
            'tax_losses' => ['source_return_id' => 'income_tax_returns'],
            'tax_loss_applications' => ['applied_return_id' => 'income_tax_returns'],
            'tax_advance_schedules' => ['source_return_id' => 'income_tax_returns'],
            default => [],
        };
    }

    /** @return array<string,array{policy:string,reason?:string}> */
    private static function secretPolicies(string $table): array
    {
        if ($table === 'supplier') {
            return self::supplierSecretPolicies();
        }
        if ($table === 'invoices') {
            return [
                'approval_token' => ['policy' => TenantSecretPolicy::OmitAndReconfigure->value],
                'approval_token_expires_at' => [
                    'policy' => TenantSecretPolicy::NotSecret->value,
                    'reason' => 'expiry_timestamp_without_token_is_inert',
                ],
                'public_token' => ['policy' => TenantSecretPolicy::OmitAndReconfigure->value],
            ];
        }
        return [];
    }

    /** @return array<string,array{policy:string}> */
    private static function supplierSecretPolicies(): array
    {
        $optional = TenantSecretPolicy::OptionalCredential->value;
        $omit = TenantSecretPolicy::OmitAndReconfigure->value;
        return [
            'idoklad_client_id' => ['policy' => $optional],
            'idoklad_client_secret_enc' => ['policy' => $optional],
            'idoklad_access_token' => ['policy' => $omit],
            'idoklad_token_expires_at' => ['policy' => $omit],
            'fakturoid_api_key_enc' => ['policy' => $optional],
            'anthropic_api_key_enc' => ['policy' => $optional],
            'fakturoid_client_id' => ['policy' => $optional],
            'fakturoid_client_secret_enc' => ['policy' => $optional],
            'fakturoid_access_token_enc' => ['policy' => $omit],
            'fakturoid_access_token_expires_at' => ['policy' => $omit],
            'azure_openai_api_key_enc' => ['policy' => $optional],
            'openai_api_key_enc' => ['policy' => $optional],
            'gemini_api_key_enc' => ['policy' => $optional],
            'ai_pseudo_salt' => ['policy' => TenantSecretPolicy::ProtectedDomainSecret->value],
        ];
    }
}
