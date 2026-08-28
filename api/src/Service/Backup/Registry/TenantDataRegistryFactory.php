<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

use MyInvoice\Service\Backup\Company\CompanyBackupAccountingClosingStepsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupClientsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupCountriesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupExpenseCategoriesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupInvoiceSettlementsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupJournalEntriesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupJournalEntryLinesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupOffsetAgreementItemsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupOffsetAgreementsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupProjectsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupRevenueCategoriesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupVatRatesProjection;

/** Produkční sestavení registru; company_backup zůstává během inventury draft. */
final class TenantDataRegistryFactory
{
    /** @var array<string,string> */
    private const COMPANY_BACKUP_ONLY_REFERENCE_TARGETS = [
        'branding_profiles' => 'core',
        'payroll_run_revisions' => 'payroll',
    ];

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

    /** @var array<string,list<string>> */
    private const COMPANY_BACKUP_DATA_COLUMNS = [
        'accounting_document_series' => [
            'id',
            'supplier_id',
            'series_code',
            'register_id',
            'fiscal_year',
            'prefix',
            'number_format',
            'next_number',
            'created_at',
            'updated_at',
        ],
        'accounting_supplier_settings' => [
            'supplier_id',
            'avg_employees',
            'statement_scope_override',
            'updated_at',
            'statutory_audit',
            'manual_doc_series',
            'fx_reversal_at_open',
            'locked_until',
            'fx_rate_mode',
            'automation_level',
            'automation_daily_limit_czk',
            'automation_digest_enabled',
            'automation_digest_hour',
            'small_asset_accrual_mode',
            'small_asset_accrual_pct',
            'fuel_account_code',
            'vehicle_repair_account_code',
            'single_analytic_redirect',
        ],
        'accounting_periods' => [
            'id',
            'supplier_id',
            'fiscal_year',
            'starts_on',
            'ends_on',
            'status',
            'closed_at',
            'row_version',
            'closed_by',
            'approved_at',
            'approved_by',
            'reviewed_at',
            'reviewed_by',
            'approval_body',
            'approval_decision_ref',
            'approval_document_hash',
            'created_at',
            'small_asset_accrual_mode',
            'small_asset_accrual_pct',
            'small_asset_flat_pct_materiality_limit',
        ],
        'chart_of_accounts' => [
            'id',
            'supplier_id',
            'account_code',
            'name',
            'account_type',
            'normal_side',
            'is_synthetic',
            'parent_id',
            'is_active',
            'created_at',
            'tax_deductibility',
            'is_clearing',
        ],
        'cost_centers' => [
            'id',
            'supplier_id',
            'code',
            'name',
            'is_active',
            'created_at',
            'updated_at',
        ],
        'currencies' => [
            'id',
            'supplier_id',
            'code',
            'label',
            'symbol',
            'name_cs',
            'name_en',
            'decimals',
            'is_active',
            'is_default',
            'account_number',
            'bank_code',
            'bank_name',
            'iban',
            'bic',
        ],
        'posting_rules' => [
            'id',
            'supplier_id',
            'rule_key',
            'description',
            'debit_account_code',
            'credit_account_code',
            'priority',
            'is_active',
            'created_at',
        ],
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
                ...self::companyBackupProjection($table),
            ];
            if ($table === 'chart_of_accounts') {
                $details['natural_key'] = ['supplier_id', 'account_code'];
            } elseif ($table === 'cost_centers') {
                $details['natural_key'] = ['supplier_id', 'code'];
            } elseif ($table === 'bank_statements') {
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
        $definitions[] = new TenantDataDefinition(
            'table:users',
            TenantDataObjectKind::Table,
            TenantDataPolicy::InstanceOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'identity',
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'table:countries',
            TenantDataObjectKind::Table,
            TenantDataPolicy::GlobalReference,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'natural_key' => ['iso2'],
                'feature_group' => 'core',
                'ownership' => [
                    'strategy' => 'tenant_reference_sources',
                    'sources' => [
                        [
                            'table' => 'clients',
                            'reference_column' => 'country_id',
                            'supplier_column' => 'supplier_id',
                        ],
                        [
                            'table' => 'supplier',
                            'reference_column' => 'country_id',
                            'supplier_column' => 'id',
                        ],
                    ],
                ],
                'secrets' => [],
                ...self::companyBackupProjection('countries'),
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'table:vat_rates',
            TenantDataObjectKind::Table,
            TenantDataPolicy::GlobalReference,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'natural_key' => ['code'],
                'feature_group' => 'tax',
                'ownership' => [
                    'strategy' => 'tenant_reference_sources',
                    'sources' => [
                        [
                            'table' => 'clients',
                            'reference_column' => 'vat_rate_default_id',
                            'supplier_column' => 'supplier_id',
                        ],
                        [
                            'table' => 'supplier',
                            'reference_column' => 'default_vat_rate_id',
                            'supplier_column' => 'id',
                        ],
                    ],
                ],
                'secrets' => [],
                ...self::companyBackupProjection('vat_rates'),
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'table:expense_categories',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'natural_key' => ['supplier_id', 'code'],
                'feature_group' => 'core',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                ...self::companyBackupProjection('expense_categories'),
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'table:invoice_settlements',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'accounting',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                ...self::companyBackupProjection('invoice_settlements'),
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'table:offset_agreements',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'natural_key' => ['supplier_id', 'document_no'],
                'feature_group' => 'accounting',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                ...self::companyBackupProjection('offset_agreements'),
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'table:offset_agreement_items',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'accounting',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                ...self::companyBackupProjection('offset_agreement_items'),
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'table:revenue_categories',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'natural_key' => ['supplier_id', 'code'],
                'feature_group' => 'core',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                ...self::companyBackupProjection('revenue_categories'),
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'table:projects',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwnedIndirect,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'core',
                'ownership' => self::foreignKeyPath('client_id', 'clients'),
                'secrets' => [],
                ...self::companyBackupProjection('projects'),
            ],
        );
        foreach (self::COMPANY_BACKUP_ONLY_REFERENCE_TARGETS as $table => $featureGroup) {
            $definitions[] = new TenantDataDefinition(
                'table:' . $table,
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantOwned,
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
                [
                    'primary_key' => ['id'],
                    'feature_group' => $featureGroup,
                    'ownership' => [
                        'strategy' => 'supplier_id',
                        'column' => 'supplier_id',
                    ],
                ],
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

    /**
     * Produkční company projekce se doplňují po ručně ověřených tabulkách.
     * Absence metadata je záměrná fail-closed hranice, ne implicitní SELECT *.
     *
     * @return array{company_backup:array{
     *   data_columns:list<string>,
     *   embedded_references:list<array<string,mixed>>,
     *   generated_columns:list<string>,
     *   omit_columns:array<string,string>,
     *   polymorphic_references?:list<array<string,mixed>>,
     *   preserved_identifiers?:list<string>,
     *   restore_overrides:array<string,array{value:string|int|bool|null,reason:string}>,
     *   references:list<array{
     *     columns:list<string>,
     *     target:string,
     *     target_columns:list<string>,
     *     mapping:string,
     *     constraint:string,
     *     nullable_columns:list<string>,
     *     fallbacks:list<string>
     *   }>
     * }}|array{}
     */
    private static function companyBackupProjection(string $table): array
    {
        $columns = match ($table) {
            'accounting_closing_steps' =>
                CompanyBackupAccountingClosingStepsProjection::dataColumns(),
            'clients' => CompanyBackupClientsProjection::dataColumns(),
            'countries' => CompanyBackupCountriesProjection::dataColumns(),
            'expense_categories' =>
                CompanyBackupExpenseCategoriesProjection::dataColumns(),
            'invoice_settlements' =>
                CompanyBackupInvoiceSettlementsProjection::dataColumns(),
            'journal_entries' => CompanyBackupJournalEntriesProjection::dataColumns(),
            'journal_entry_lines' =>
                CompanyBackupJournalEntryLinesProjection::dataColumns(),
            'offset_agreement_items' =>
                CompanyBackupOffsetAgreementItemsProjection::dataColumns(),
            'offset_agreements' =>
                CompanyBackupOffsetAgreementsProjection::dataColumns(),
            'projects' => CompanyBackupProjectsProjection::dataColumns(),
            'revenue_categories' =>
                CompanyBackupRevenueCategoriesProjection::dataColumns(),
            'vat_rates' => CompanyBackupVatRatesProjection::dataColumns(),
            default => self::COMPANY_BACKUP_DATA_COLUMNS[$table] ?? null,
        };
        if ($columns === null) {
            return [];
        }
        $embeddedReferences = $table === 'accounting_closing_steps'
            ? CompanyBackupAccountingClosingStepsProjection::embeddedReferences()
            : [];
        $references = self::companyBackupReferences($table);
        $restoreOverrides = self::companyBackupRestoreOverrides($table);
        if ($table === 'journal_entries') {
            return [
                'company_backup' => [
                    'data_columns' => $columns,
                    'embedded_references' => $embeddedReferences,
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'polymorphic_references' =>
                        CompanyBackupJournalEntriesProjection::polymorphicReferences(),
                    'references' => $references,
                    'restore_overrides' => $restoreOverrides,
                ],
            ];
        }
        if ($table === 'clients') {
            return [
                'company_backup' => [
                    'data_columns' => $columns,
                    'embedded_references' => $embeddedReferences,
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'preserved_identifiers' =>
                        CompanyBackupClientsProjection::preservedIdentifiers(),
                    'references' => $references,
                    'restore_overrides' => $restoreOverrides,
                ],
            ];
        }
        return [
            'company_backup' => [
                'data_columns' => $columns,
                'embedded_references' => $embeddedReferences,
                'generated_columns' => [],
                'omit_columns' => [],
                'references' => $references,
                'restore_overrides' => $restoreOverrides,
            ],
        ];
    }

    /** @return array<string,array{value:string|int|bool|null,reason:string}> */
    private static function companyBackupRestoreOverrides(string $table): array
    {
        return match ($table) {
            'accounting_supplier_settings' => [
                'automation_digest_enabled' => [
                    'value' => 0,
                    'reason' => 'disable_automation_after_restore',
                ],
                'automation_level' => [
                    'value' => 'off',
                    'reason' => 'disable_automation_after_restore',
                ],
            ],
            default => [],
        };
    }

    /**
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
    private static function companyBackupReferences(string $table): array
    {
        return match ($table) {
            'accounting_closing_steps' =>
                CompanyBackupAccountingClosingStepsProjection::references(),
            'clients' => CompanyBackupClientsProjection::references(),
            'expense_categories' =>
                CompanyBackupExpenseCategoriesProjection::references(),
            'invoice_settlements' =>
                CompanyBackupInvoiceSettlementsProjection::references(),
            'journal_entries' => CompanyBackupJournalEntriesProjection::references(),
            'journal_entry_lines' => CompanyBackupJournalEntryLinesProjection::references(),
            'offset_agreement_items' =>
                CompanyBackupOffsetAgreementItemsProjection::references(),
            'offset_agreements' =>
                CompanyBackupOffsetAgreementsProjection::references(),
            'projects' => CompanyBackupProjectsProjection::references(),
            'revenue_categories' =>
                CompanyBackupRevenueCategoriesProjection::references(),
            'accounting_document_series' => [
                self::companyBackupTenantIdOrZeroReference(
                    'register_id',
                    'cash_registers',
                ),
                self::companyBackupSupplierReference(),
            ],
            'accounting_supplier_settings' => [
                self::companyBackupTenantNaturalKeyReference(
                    ['supplier_id', 'fuel_account_code'],
                    'chart_of_accounts',
                    ['supplier_id', 'account_code'],
                    ['fuel_account_code'],
                ),
                self::companyBackupTenantNaturalKeyReference(
                    ['supplier_id', 'vehicle_repair_account_code'],
                    'chart_of_accounts',
                    ['supplier_id', 'account_code'],
                    ['vehicle_repair_account_code'],
                ),
                self::companyBackupSupplierReference(),
            ],
            'accounting_periods' => [
                self::companyBackupActorReference('approved_by'),
                self::companyBackupActorReference('closed_by'),
                self::companyBackupActorReference('reviewed_by'),
                self::companyBackupSupplierReference(),
            ],
            'chart_of_accounts' => [
                self::companyBackupTenantReference(
                    'parent_id',
                    'chart_of_accounts',
                    nullable: true,
                ),
                self::companyBackupSupplierReference(),
            ],
            'cost_centers' => [self::companyBackupSupplierReference()],
            'currencies' => [self::companyBackupSupplierReference()],
            'posting_rules' => [
                self::companyBackupTenantNaturalKeyReference(
                    ['supplier_id', 'credit_account_code'],
                    'chart_of_accounts',
                    ['supplier_id', 'account_code'],
                    ['supplier_id', 'credit_account_code'],
                ),
                self::companyBackupTenantNaturalKeyReference(
                    ['supplier_id', 'debit_account_code'],
                    'chart_of_accounts',
                    ['supplier_id', 'account_code'],
                    ['supplier_id', 'debit_account_code'],
                ),
                self::companyBackupSupplierReference(nullable: true),
            ],
            default => [],
        };
    }

    /**
     * @return array{
     *   columns:list<string>,
     *   target:string,
     *   target_columns:list<string>,
     *   mapping:string,
     *   constraint:string,
     *   nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private static function companyBackupSupplierReference(bool $nullable = false): array
    {
        return self::companyBackupTenantReference(
            'supplier_id',
            'supplier',
            $nullable,
        );
    }

    /**
     * @return array{
     *   columns:list<string>,
     *   target:string,
     *   target_columns:list<string>,
     *   mapping:string,
     *   constraint:string,
     *   nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private static function companyBackupTenantReference(
        string $column,
        string $target,
        bool $nullable = false,
    ): array {
        return [
            'columns' => [$column],
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => [],
        ];
    }

    /**
     * @return array{
     *   columns:list<string>,
     *   target:string,
     *   target_columns:list<string>,
     *   mapping:string,
     *   constraint:string,
     *   nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private static function companyBackupTenantIdOrZeroReference(
        string $column,
        string $target,
    ): array {
        return [
            'columns' => [$column],
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantIdOrZero->value,
            'constraint' => CompanyBackupReferenceConstraint::Optional->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ];
    }

    /**
     * @param list<string> $columns
     * @param list<string> $targetColumns
     * @param list<string> $nullableColumns
     * @return array{
     *   columns:list<string>,
     *   target:string,
     *   target_columns:list<string>,
     *   mapping:string,
     *   constraint:string,
     *   nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private static function companyBackupTenantNaturalKeyReference(
        array $columns,
        string $target,
        array $targetColumns,
        array $nullableColumns,
    ): array {
        return [
            'columns' => $columns,
            'target' => 'table:' . $target,
            'target_columns' => $targetColumns,
            'mapping' => CompanyBackupReferenceMapping::TenantNaturalKey->value,
            'constraint' => CompanyBackupReferenceConstraint::Optional->value,
            'nullable_columns' => $nullableColumns,
            'fallbacks' => [],
        ];
    }

    /**
     * @return array{
     *   columns:list<string>,
     *   target:string,
     *   target_columns:list<string>,
     *   mapping:string,
     *   constraint:string,
     *   nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private static function companyBackupActorReference(string $column): array
    {
        return [
            'columns' => [$column],
            'target' => 'table:users',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::Actor->value,
            'constraint' => CompanyBackupReferenceConstraint::Optional->value,
            'nullable_columns' => [$column],
            'fallbacks' => ['null', 'restore_actor'],
        ];
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
