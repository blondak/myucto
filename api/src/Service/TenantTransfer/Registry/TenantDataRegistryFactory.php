<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Produkční sestavení registru; transfer profil čeká na dokončení inventury. */
final class TenantDataRegistryFactory
{
    /**
     * Stabilní pořadí stávajícího účetního archivu. Je součástí registru, aby
     * export, obnova a tenant transfer neudržovaly vlastní konkurenční seznamy.
     *
     * @var list<string>
     */
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

    /**
     * Rodiče před dětmi pro obnovu archivu. Odlišnost od exportního pořadí je
     * záměrná; cykly a dopředné reference řeší druhý průchod importéru.
     *
     * @var list<string>
     */
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

    /**
     * První ověřená dávka používá stejné přímé tenant selektory jako účetní
     * ArchiveService. Přenosový profil zůstává neúplný, dokud nejsou explicitně
     * zařazené i všechny ostatní agendy aktuálního schématu.
     *
     * @var array<string,array<string,list<string>>>
     */
    private const DIRECT_TABLE_GROUPS = [
        'core' => [
            'clients' => ['id'],
            'client_bank_accounts' => ['id'],
            'currencies' => ['id'],
            'invoices' => ['id'],
            'purchase_invoices' => ['id'],
            'invoice_payments' => ['id'],
            'payment_matches' => ['id'],
        ],
        'accounting' => [
            'accounting_periods' => ['id'],
            'chart_of_accounts' => ['id'],
            'posting_rules' => ['id'],
            'cost_centers' => ['id'],
            'accounting_supplier_settings' => ['supplier_id'],
            'accounting_closing_steps' => ['id'],
            'accounting_document_series' => ['id'],
            'journal_entries' => ['id'],
            'journal_entry_lines' => ['id'],
            'assets' => ['id'],
            'asset_improvements' => ['id'],
            'depreciation_entries' => ['id'],
            'cash_registers' => ['id'],
            'cash_documents' => ['id'],
        ],
        'bank' => [
            'bank_statements' => ['id'],
        ],
        'tax' => [
            'income_tax_returns' => ['id'],
            'tax_losses' => ['id'],
            'tax_loss_applications' => ['id'],
            'tax_advance_schedules' => ['id'],
        ],
        'documents' => [
            'journal_entry_attachments' => ['id'],
        ],
        'stock' => [
            'warehouses' => ['id'],
            'stock_items' => ['id'],
            'manufacturers' => ['id'],
            'stock_media' => ['id'],
            'stock_categories' => ['id'],
            'stock_category_i18n' => ['id'],
            'stock_item_categories' => ['stock_item_id', 'category_id'],
            'stock_tags' => ['id'],
            'stock_item_tags' => ['stock_item_id', 'tag_id'],
            'stock_attributes' => ['id'],
            'stock_attribute_options' => ['id'],
            'stock_attribute_i18n' => ['id'],
            'stock_item_attribute_values' => ['id'],
            'stock_fee_types' => ['id'],
            'stock_item_fees' => ['id'],
            'stock_item_prices' => ['id'],
            'stock_item_vendors' => ['id'],
            'stock_item_i18n' => ['id'],
            'stock_levels' => ['supplier_id', 'warehouse_id', 'stock_item_id'],
            'stock_documents' => ['id'],
            'stock_document_lines' => ['id'],
            'stock_landed_costs' => ['id'],
            'stock_takes' => ['id'],
            'stock_take_lines' => ['id'],
        ],
    ];

    /**
     * @var array<string,array{
     *   primary_key:list<string>,
     *   feature_group:string,
     *   path:list<array{from_column:string,to_table:string,to_column:string}>
     * }>
     */
    private const INDIRECT_TABLES = [
        'cash_document_vat_lines' => [
            'primary_key' => ['id'],
            'feature_group' => 'accounting',
            'path' => [
                ['from_column' => 'cash_document_id', 'to_table' => 'cash_documents', 'to_column' => 'id'],
                ['from_column' => 'supplier_id', 'to_table' => 'supplier', 'to_column' => 'id'],
            ],
        ],
        'invoice_items' => [
            'primary_key' => ['id'],
            'feature_group' => 'core',
            'path' => [
                ['from_column' => 'invoice_id', 'to_table' => 'invoices', 'to_column' => 'id'],
                ['from_column' => 'supplier_id', 'to_table' => 'supplier', 'to_column' => 'id'],
            ],
        ],
        'purchase_invoice_items' => [
            'primary_key' => ['id'],
            'feature_group' => 'core',
            'path' => [
                ['from_column' => 'purchase_invoice_id', 'to_table' => 'purchase_invoices', 'to_column' => 'id'],
                ['from_column' => 'supplier_id', 'to_table' => 'supplier', 'to_column' => 'id'],
            ],
        ],
        'invoice_pdfs' => [
            'primary_key' => ['id'],
            'feature_group' => 'documents',
            'path' => [
                ['from_column' => 'invoice_id', 'to_table' => 'invoices', 'to_column' => 'id'],
                ['from_column' => 'supplier_id', 'to_table' => 'supplier', 'to_column' => 'id'],
            ],
        ],
        'invoice_attachments' => [
            'primary_key' => ['id'],
            'feature_group' => 'documents',
            'path' => [
                ['from_column' => 'invoice_id', 'to_table' => 'invoices', 'to_column' => 'id'],
                ['from_column' => 'supplier_id', 'to_table' => 'supplier', 'to_column' => 'id'],
            ],
        ],
    ];

    public static function draftV1(): TenantDataRegistry
    {
        $definitions = [self::supplier()];
        foreach (self::DIRECT_TABLE_GROUPS as $featureGroup => $tables) {
            foreach ($tables as $table => $primaryKey) {
                $definitions[] = self::tenantOwned(
                    $table,
                    $primaryKey,
                    $featureGroup,
                    self::secretPolicies($table),
                );
            }
        }
        foreach (self::INDIRECT_TABLES as $table => $details) {
            $definitions[] = self::tenantOwnedIndirect(
                $table,
                $details['primary_key'],
                $details['feature_group'],
                $details['path'],
                self::secretPolicies($table),
            );
        }
        $definitions[] = new TenantDataDefinition(
            'table:accounting_archives',
            TenantDataObjectKind::Table,
            TenantDataPolicy::RuntimeDerived,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'accounting',
                'reason' => 'generated_archive_metadata_and_local_file',
            ],
        );

        // Dvě tabulky patří do staršího účetního archivu, ale v úplném
        // cross-instance profilu ještě nemají dokončenou cílovou politiku.
        // Archive-only klasifikace je drží ve společném SSOT bez předstírání,
        // že už jsou bezpečně přenositelné mezi instancemi.
        $definitions[] = self::archiveBankTransactions();
        $definitions[] = self::archiveExchangeRates();
        $definitions = self::withAccountingArchiveProfile($definitions);

        return new TenantDataRegistry(
            1,
            $definitions,
            [TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE],
        );
    }

    private static function archiveBankTransactions(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:bank_transactions',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwnedIndirect,
            [TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'bank',
                'ownership' => [
                    'strategy' => 'relationship_union',
                    'reason' => 'statement_invoice_or_client_account_relation',
                ],
                'secrets' => [],
            ],
        );
    }

    private static function archiveExchangeRates(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:exchange_rates',
            TenantDataObjectKind::Table,
            TenantDataPolicy::GlobalReference,
            [TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE],
            [
                'primary_key' => ['rate_date', 'currency_code'],
                'feature_group' => 'accounting',
                'natural_key' => ['rate_date', 'currency_code'],
                'secrets' => [],
            ],
        );
    }

    /**
     * @param list<TenantDataDefinition> $definitions
     * @return list<TenantDataDefinition>
     */
    private static function withAccountingArchiveProfile(array $definitions): array
    {
        $exportIsUnique = count(array_unique(
            self::ACCOUNTING_ARCHIVE_EXPORT_ORDER,
            SORT_STRING,
        )) === count(self::ACCOUNTING_ARCHIVE_EXPORT_ORDER);
        $restoreIsUnique = count(array_unique(
            self::ACCOUNTING_ARCHIVE_RESTORE_ORDER,
            SORT_STRING,
        )) === count(self::ACCOUNTING_ARCHIVE_RESTORE_ORDER);
        $sameTables = array_diff(
            self::ACCOUNTING_ARCHIVE_EXPORT_ORDER,
            self::ACCOUNTING_ARCHIVE_RESTORE_ORDER,
        ) === [] && array_diff(
            self::ACCOUNTING_ARCHIVE_RESTORE_ORDER,
            self::ACCOUNTING_ARCHIVE_EXPORT_ORDER,
        ) === [];
        if (!$exportIsUnique || !$restoreIsUnique || !$sameTables) {
            throw new \LogicException(
                'Export a obnova účetního archivu nemají stejnou sadu tabulek.',
            );
        }
        $byTable = [];
        foreach ($definitions as $index => $definition) {
            if (!str_starts_with($definition->key, 'table:')) {
                continue;
            }
            $byTable[substr($definition->key, strlen('table:'))] = $index;
        }

        foreach (self::ACCOUNTING_ARCHIVE_EXPORT_ORDER as $exportIndex => $table) {
            $definitionIndex = $byTable[$table] ?? null;
            $restoreIndex = array_search(
                $table,
                self::ACCOUNTING_ARCHIVE_RESTORE_ORDER,
                true,
            );
            if ($definitionIndex === null || $restoreIndex === false) {
                throw new \LogicException(
                    'Účetní archiv odkazuje na tabulku bez úplné registrace.',
                );
            }

            $definition = $definitions[$definitionIndex];
            $profiles = $definition->profiles;
            if (!in_array(
                TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
                $profiles,
                true,
            )) {
                $profiles[] = TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE;
            }
            $details = $definition->details;
            $details['accounting_archive'] = self::accountingArchiveDetails(
                $table,
                $exportIndex + 1,
                $restoreIndex + 1,
                $details,
            );
            $definitions[$definitionIndex] = new TenantDataDefinition(
                $definition->key,
                $definition->kind,
                $definition->policy,
                $profiles,
                $details,
            );
        }

        return array_values($definitions);
    }

    /**
     * @param array<string,mixed> $definitionDetails
     * @return array<string,mixed>
     */
    private static function accountingArchiveDetails(
        string $table,
        int $exportOrder,
        int $restoreOrder,
        array $definitionDetails,
    ): array {
        $details = [
            'export_order' => $exportOrder,
            'restore_order' => $restoreOrder,
            'selector' => match ($table) {
                'bank_transactions' => 'bank_transaction_relationships',
                'bank_statements' => 'bank_statement_relationships',
                'exchange_rates' => 'accounting_period_currency',
                default => 'ownership',
            },
            'omit_columns' => match ($table) {
                'supplier' => self::accountingArchiveSupplierOmissions(),
                'bank_statements' => ['file_content', 'pdf_content'],
                default => [],
            },
            'soft_references' => self::accountingArchiveSoftReferences($table),
        ];
        if (($definitionDetails['feature_group'] ?? null) === 'stock') {
            $details['feature_flag'] = 'stock_enabled';
        }
        return $details;
    }

    /** @return list<string> */
    private static function accountingArchiveSupplierOmissions(): array
    {
        return [
            'idoklad_client_id',
            'fakturoid_client_id',
            'fakturoid_access_token_expires_at',
        ];
    }

    /** @return array<string,string> */
    private static function accountingArchiveSoftReferences(string $table): array
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
            'tax_loss_applications' => [
                'applied_return_id' => 'income_tax_returns',
            ],
            'tax_advance_schedules' => [
                'source_return_id' => 'income_tax_returns',
            ],
            default => [],
        };
    }

    private static function supplier(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:supplier',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantRoot,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'core',
                'ownership' => [
                    'strategy' => 'selected_supplier',
                    'column' => 'id',
                ],
                'secrets' => self::supplierSecretPolicies(),
                'post_import' => [
                    'disable_integrations' => true,
                ],
            ],
        );
    }

    /**
     * @param list<string> $primaryKey
     * @param array<string,array<string,string>> $secrets
     */
    private static function tenantOwned(
        string $table,
        array $primaryKey,
        string $featureGroup,
        array $secrets,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => $primaryKey,
                'feature_group' => $featureGroup,
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => $secrets,
            ],
        );
    }

    /**
     * @param list<string> $primaryKey
     * @param list<array{from_column:string,to_table:string,to_column:string}> $path
     * @param array<string,array<string,string>> $secrets
     */
    private static function tenantOwnedIndirect(
        string $table,
        array $primaryKey,
        string $featureGroup,
        array $path,
        array $secrets,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwnedIndirect,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => $primaryKey,
                'feature_group' => $featureGroup,
                'ownership' => [
                    'strategy' => 'foreign_key_path',
                    'path' => $path,
                ],
                'secrets' => $secrets,
            ],
        );
    }

    /** @return array<string,array<string,string>> */
    private static function secretPolicies(string $table): array
    {
        if ($table !== 'invoices') {
            return [];
        }
        return [
            'approval_token' => ['policy' => 'omit_and_reconfigure'],
            'approval_token_expires_at' => [
                'policy' => 'not_secret',
                'reason' => 'expiry_timestamp_reset_with_token',
            ],
            'public_token' => ['policy' => 'omit_and_reconfigure'],
        ];
    }

    /** @return array<string,array<string,string>> */
    private static function supplierSecretPolicies(): array
    {
        return [
            'idoklad_client_secret_enc' => ['policy' => 'reencrypt_v1'],
            // Legacy iDoklad access token je plaintext cache. Do snapshotu
            // nepatří; cíl si vyžádá novou autorizaci.
            'idoklad_access_token' => ['policy' => 'omit_and_reconfigure'],
            'idoklad_token_expires_at' => [
                'policy' => 'not_secret',
                'reason' => 'expiry_timestamp_reset_with_token',
            ],
            'fakturoid_api_key_enc' => ['policy' => 'reencrypt_v1'],
            'anthropic_api_key_enc' => ['policy' => 'reencrypt_v1'],
            'fakturoid_client_secret_enc' => ['policy' => 'reencrypt_v1'],
            // OAuth access token je obnovitelná cache, nikoli dlouhodobá
            // konfigurace integrace.
            'fakturoid_access_token_enc' => ['policy' => 'omit_and_reconfigure'],
            'fakturoid_access_token_expires_at' => [
                'policy' => 'not_secret',
                'reason' => 'expiry_timestamp_reset_with_token',
            ],
            'azure_openai_api_key_enc' => ['policy' => 'reencrypt_v1'],
            'openai_api_key_enc' => ['policy' => 'reencrypt_v1'],
            'gemini_api_key_enc' => ['policy' => 'reencrypt_v1'],
        ];
    }
}
