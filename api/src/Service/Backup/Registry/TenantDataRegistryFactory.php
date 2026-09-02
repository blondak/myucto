<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

use MyInvoice\Service\Backup\Company\CompanyBackupAccountingClosingStepsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupBrandingProfilesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupClientsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupCountriesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupEmailProfilesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupExpenseCategoriesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupInvoiceSettlementsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupJournalEntriesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupJournalEntryLinesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupOffsetAgreementItemsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupOffsetAgreementsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollAbsencesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollAverageEarningSnapshotsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollBusinessTripFreeMealsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollBusinessTripItemsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollBusinessTripsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollCalendarDaysProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollComponentDefinitionsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollDeductionAgreementVersionsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollDeductionAgreementsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollDeductionLedgerProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollDependantsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollDimensionsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollEmployeeProfilesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollEmployeesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollEmployerPoliciesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollEmploymentChecklistItemsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollEmploymentDimensionsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollEmploymentEventsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollEmploymentsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollEmploymentTermsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollInputImportsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollInputsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollInstitutionAccountsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollInstitutionsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollOfficesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollOvertimeAveragingPeriodsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollOvertimeCompensationsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollOvertimeConsentsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollOvertimeProtectionsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollPersonAddressesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollPersonHealthCoverageProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollPersonHealthMinimumReductionsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollPersonHealthMonthEvidenceProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollPersonHealthOtherEmployerBasesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollPersonIdentityHistoryProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollPersonSocialDiscountClaimsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollPersonSocialJurisdictionsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollPersonTaxChildClaimsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollPersonTaxCreditClaimsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollPersonTaxDeclarationsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollPersonTaxResidencesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollRecurringComponentsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollRunPersonsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollRunRevisionsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollRunsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollShiftsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollSicknessCompensationSegmentsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollSicknessEventsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollStatutoryPersonResultsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollTimeEntriesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollTimeMonthsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollTravelCompensationLinksProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollWorkCalendarsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupPdfSignatureOutputSettingsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupProjectsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupRevenueCategoriesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupSignatureDocumentOverridesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupSignatureRoleProfilesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretStorage;
use MyInvoice\Service\Backup\Company\CompanyBackupSigningCredentialsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupSigningProfilesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupSigningSettingsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupVatRatesProjection;

/** Produkční sestavení registru; company_backup zůstává během inventury draft. */
final class TenantDataRegistryFactory
{
    /** @var array<string,string> */
    private const COMPANY_BACKUP_ONLY_REFERENCE_TARGETS = [
        'payroll_absences' => 'payroll',
        'payroll_average_earning_snapshots' => 'payroll',
        'payroll_business_trip_free_meals' => 'payroll',
        'payroll_business_trip_items' => 'payroll',
        'payroll_business_trips' => 'payroll',
        'payroll_calendar_days' => 'payroll',
        'payroll_component_definitions' => 'payroll',
        'payroll_deduction_agreement_versions' => 'payroll',
        'payroll_deduction_agreements' => 'payroll',
        'payroll_deduction_ledger' => 'payroll',
        'payroll_dependants' => 'payroll',
        'payroll_dimensions' => 'payroll',
        'payroll_employees' => 'payroll',
        'payroll_employer_policies' => 'payroll',
        'payroll_employment_checklist_items' => 'payroll',
        'payroll_employment_dimensions' => 'payroll',
        'payroll_employment_events' => 'payroll',
        'payroll_employments' => 'payroll',
        'payroll_employment_terms' => 'payroll',
        'payroll_input_imports' => 'payroll',
        'payroll_inputs' => 'payroll',
        'payroll_insolvency_payment_instructions' => 'payroll',
        'payroll_institution_accounts' => 'payroll',
        'payroll_institutions' => 'payroll',
        'payroll_jmhz_work_month_revisions' => 'payroll',
        'payroll_offices' => 'payroll',
        'payroll_overtime_averaging_periods' => 'payroll',
        'payroll_overtime_compensations' => 'payroll',
        'payroll_overtime_consents' => 'payroll',
        'payroll_overtime_protections' => 'payroll',
        'payroll_payout_rules' => 'payroll',
        'payroll_person_accounts' => 'payroll',
        'payroll_person_addresses' => 'payroll',
        'payroll_person_health_coverage_history' => 'payroll',
        'payroll_person_health_minimum_reductions' => 'payroll',
        'payroll_person_health_month_evidence' => 'payroll',
        'payroll_person_health_other_employer_bases' => 'payroll',
        'payroll_person_identity_history' => 'payroll',
        'payroll_person_social_discount_claims' => 'payroll',
        'payroll_person_social_jurisdictions' => 'payroll',
        'payroll_person_tax_child_claims' => 'payroll',
        'payroll_person_tax_credit_claims' => 'payroll',
        'payroll_person_tax_declarations' => 'payroll',
        'payroll_person_tax_residences' => 'payroll',
        'payroll_recurring_components' => 'payroll',
        'payroll_risky_savings_evidence' => 'payroll',
        'payroll_runs' => 'payroll',
        'payroll_run_persons' => 'payroll',
        'payroll_run_revisions' => 'payroll',
        'payroll_shifts' => 'payroll',
        'payroll_sickness_compensation_segments' => 'payroll',
        'payroll_sickness_events' => 'payroll',
        'payroll_statutory_accumulator_entries' => 'payroll',
        'payroll_statutory_accumulator_openings' => 'payroll',
        'payroll_statutory_person_results' => 'payroll',
        'payroll_statutory_results' => 'payroll',
        'payroll_time_entries' => 'payroll',
        'payroll_time_months' => 'payroll',
        'payroll_travel_compensation_links' => 'payroll',
        'payroll_work_calendars' => 'payroll',
    ];

    /** @var array<string,list<list<string>>> */
    private const COMPANY_BACKUP_REFERENCE_KEYS = [
        'payroll_deduction_agreements' => [
            ['supplier_id', 'id', 'employee_id'],
        ],
        'payroll_employments' => [
            ['supplier_id', 'id', 'employee_id'],
        ],
        'payroll_run_persons' => [
            ['supplier_id', 'revision_id', 'employee_id'],
        ],
        'payroll_run_revisions' => [
            ['supplier_id', 'id', 'run_id'],
        ],
        'payroll_statutory_person_results' => [[
            'supplier_id',
            'id',
            'statutory_result_id',
            'revision_id',
            'calculation_kind',
            'employee_id',
        ]],
        'payroll_statutory_results' => [[
            'supplier_id',
            'id',
            'revision_id',
            'calculation_kind',
        ]],
    ];

    /** @var array<string,list<string>> */
    private const COMPANY_BACKUP_NATURAL_KEYS = [
        'payroll_average_earning_snapshots' => [
            'supplier_id',
            'employment_id',
            'applicable_year',
            'applicable_quarter',
            'revision_no',
        ],
        'payroll_business_trip_free_meals' => [
            'supplier_id',
            'trip_id',
            'meal_date',
        ],
        'payroll_calendar_days' => [
            'supplier_id',
            'calendar_id',
            'day_date',
        ],
        'payroll_component_definitions' => ['supplier_id', 'code', 'valid_from'],
        'payroll_deduction_agreement_versions' => [
            'supplier_id',
            'agreement_id',
            'version_no',
        ],
        'payroll_deduction_agreements' => [
            'supplier_id',
            'employee_id',
            'agreement_reference',
        ],
        'payroll_deduction_ledger' => ['supplier_id', 'event_key_hash'],
        'payroll_employment_terms' => [
            'supplier_id',
            'employment_id',
            'effective_from',
        ],
        'payroll_employer_policies' => ['supplier_id', 'valid_from'],
        'payroll_employment_checklist_items' => [
            'supplier_id',
            'employment_id',
            'phase',
            'item_key',
        ],
        'payroll_input_imports' => [
            'supplier_id',
            'period_start',
            'content_hash',
        ],
        'payroll_institutions' => [
            'supplier_id',
            'institution_type',
            'institution_code',
        ],
        'payroll_offices' => ['supplier_id', 'code'],
        'payroll_overtime_averaging_periods' => ['supplier_id', 'valid_from'],
        'payroll_overtime_compensations' => [
            'supplier_id',
            'employment_id',
            'overtime_date',
        ],
        'payroll_overtime_protections' => [
            'supplier_id',
            'employment_id',
            'protection',
            'valid_from',
        ],
        'payroll_person_addresses' => [
            'supplier_id',
            'employee_id',
            'address_type',
            'effective_from',
        ],
        'payroll_person_health_coverage_history' => [
            'supplier_id',
            'employee_id',
            'effective_from',
        ],
        'payroll_person_health_minimum_reductions' => [
            'supplier_id',
            'employee_id',
            'reason',
            'effective_from',
        ],
        'payroll_person_health_month_evidence' => [
            'supplier_id',
            'employee_id',
            'period_start',
        ],
        'payroll_person_health_other_employer_bases' => [
            'supplier_id',
            'employee_id',
            'period_start',
            'employer_reference',
        ],
        'payroll_person_identity_history' => [
            'supplier_id',
            'employee_id',
            'effective_from',
        ],
        'payroll_person_social_discount_claims' => [
            'supplier_id',
            'employee_id',
            'effective_from',
        ],
        'payroll_person_social_jurisdictions' => [
            'supplier_id',
            'employee_id',
            'effective_from',
        ],
        'payroll_person_tax_child_claims' => [
            'supplier_id',
            'employee_id',
            'child_reference',
            'effective_from',
        ],
        'payroll_person_tax_credit_claims' => [
            'supplier_id',
            'employee_id',
            'credit_kind',
            'effective_from',
        ],
        'payroll_person_tax_declarations' => [
            'supplier_id',
            'employee_id',
            'effective_from',
        ],
        'payroll_person_tax_residences' => [
            'supplier_id',
            'employee_id',
            'effective_from',
        ],
        'payroll_recurring_components' => [
            'supplier_id',
            'employment_id',
            'component_id',
            'valid_from',
        ],
        'payroll_run_persons' => [
            'supplier_id',
            'revision_id',
            'employee_id',
        ],
        'payroll_shifts' => ['supplier_id', 'series_key', 'revision_no'],
        'payroll_sickness_events' => ['supplier_id', 'absence_id'],
        'payroll_time_entries' => ['supplier_id', 'series_key', 'revision_no'],
        'payroll_time_months' => [
            'supplier_id',
            'employment_id',
            'period_start',
        ],
        'payroll_travel_compensation_links' => [
            'supplier_id',
            'source_system',
            'source_reference',
        ],
        'payroll_work_calendars' => [
            'supplier_id',
            'employment_id',
            'valid_from',
        ],
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
        $definitions[] = new TenantDataDefinition(
            'table:payroll_employee_profiles',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['supplier_id', 'employee_id'],
                'feature_group' => 'payroll',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                ...self::companyBackupProjection('payroll_employee_profiles'),
            ],
        );
        foreach (self::COMPANY_BACKUP_ONLY_REFERENCE_TARGETS as $table => $featureGroup) {
            $projection = self::companyBackupProjection($table);
            $details = [
                'primary_key' => ['id'],
                ...(isset(self::COMPANY_BACKUP_NATURAL_KEYS[$table]) ? [
                    'natural_key' => self::COMPANY_BACKUP_NATURAL_KEYS[$table],
                ] : []),
                'feature_group' => $featureGroup,
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                ...(isset(self::COMPANY_BACKUP_REFERENCE_KEYS[$table]) ? [
                    'reference_keys' => self::COMPANY_BACKUP_REFERENCE_KEYS[$table],
                ] : []),
                ...($projection === [] ? [] : [
                    'secrets' => self::secretPolicies($table),
                ]),
                ...$projection,
            ];
            $definitions[] = new TenantDataDefinition(
                'table:' . $table,
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantOwned,
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
                $details,
            );
        }
        $definitions[] = new TenantDataDefinition(
            'table:epo_signing_credentials',
            TenantDataObjectKind::Table,
            TenantDataPolicy::PersonalSecretAttachment,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'integrations',
                'ownership' => [
                    'owner_column' => 'owner_user_id',
                    'strategy' => 'personal_credential_owner',
                ],
                'reason' => 'select_individually_with_dual_consent',
                'secrets' => [
                    'passphrase_ciphertext' => [
                        'policy' =>
                            TenantSecretPolicy::PersonalWithDualConsent->value,
                    ],
                    'pfx_ciphertext' => [
                        'policy' =>
                            TenantSecretPolicy::PersonalWithDualConsent->value,
                    ],
                ],
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'table:epo_signing_credential_suppliers',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantRelation,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['credential_id', 'supplier_id'],
                'feature_group' => 'integrations',
                'ownership' => [
                    'actor_column' => 'enabled_by',
                    'credential_column' => 'credential_id',
                    'strategy' => 'credential_consent_relation',
                    'supplier_column' => 'supplier_id',
                ],
                'reason' => 'recreate_from_credential_consent_decisions',
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'table:signing_profiles',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'natural_key' => ['supplier_id', 'code'],
                'feature_group' => 'integrations',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [
                    'pdf_tsa_password_enc' => [
                        'policy' => TenantSecretPolicy::OptionalCredential->value,
                        'storage' =>
                            CompanyBackupSecretStorage::ApplicationEncrypted->value,
                    ],
                ],
                ...self::companyBackupProjection('signing_profiles'),
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'table:signing_credentials',
            TenantDataObjectKind::Table,
            TenantDataPolicy::OptionalCredential,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'integrations',
                'ownership' =>
                    CompanyBackupSigningCredentialsProjection::ownership(),
                'reason' => 'omit_until_explicit_credential_selection',
                'company_backup_credential' =>
                    CompanyBackupSigningCredentialsProjection::metadata(),
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'table:signing_settings',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['supplier_id'],
                'feature_group' => 'integrations',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                ...self::companyBackupProjection('signing_settings'),
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'table:pdf_signature_output_settings',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['supplier_id', 'output_type'],
                'feature_group' => 'integrations',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                ...self::companyBackupProjection('pdf_signature_output_settings'),
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'table:signature_role_profiles',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['supplier_id', 'usage', 'output_type', 'role'],
                'feature_group' => 'integrations',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                ...self::companyBackupProjection('signature_role_profiles'),
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'table:signature_document_overrides',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['supplier_id', 'usage', 'entity_type', 'entity_id'],
                'feature_group' => 'integrations',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                ...self::companyBackupProjection('signature_document_overrides'),
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'table:signature_user_profiles',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantRelation,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['supplier_id', 'usage', 'output_type', 'user_id'],
                'feature_group' => 'integrations',
                'ownership' => [
                    'actor_column' => 'user_id',
                    'strategy' => 'restore_actor_relation',
                    'supplier_column' => 'supplier_id',
                ],
                'reason' => 'recreate_after_explicit_actor_mapping',
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'table:email_profiles',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'natural_key' => ['supplier_id', 'code'],
                'feature_group' => 'integrations',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [
                    'imap_encryption' => [
                        'policy' => TenantSecretPolicy::NotSecret->value,
                        'reason' => 'transport_encryption_mode_without_secret',
                    ],
                    'imap_password_enc' => [
                        'policy' => TenantSecretPolicy::OptionalCredential->value,
                        'storage' =>
                            CompanyBackupSecretStorage::ApplicationEncrypted->value,
                    ],
                    'smtp_encryption' => [
                        'policy' => TenantSecretPolicy::NotSecret->value,
                        'reason' => 'transport_encryption_mode_without_secret',
                    ],
                    'smtp_password_enc' => [
                        'policy' => TenantSecretPolicy::OptionalCredential->value,
                        'storage' =>
                            CompanyBackupSecretStorage::ApplicationEncrypted->value,
                    ],
                ],
                ...self::companyBackupProjection('email_profiles'),
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'table:branding_profiles',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'natural_key' => ['supplier_id', 'name'],
                'feature_group' => 'core',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                ...self::companyBackupProjection('branding_profiles'),
            ],
        );
        $definitions[] = new TenantDataDefinition(
            'file-area:supplier-logos',
            TenantDataObjectKind::FileArea,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'feature_group' => 'core',
                'file_policy' => 'historical_optional',
                'path_policy' => 'supplier_logo',
                'file_owners' => [
                    [
                        'registry_key' => 'table:branding_profiles',
                        'column' => 'logo_path',
                        'path' => [],
                        'stored_prefix' => 'storage/supplier-logos/',
                    ],
                    [
                        'registry_key' => 'table:invoices',
                        'column' => 'supplier_snapshot',
                        'path' => ['logo_path'],
                        'stored_prefix' => 'storage/supplier-logos/',
                    ],
                    [
                        'registry_key' => 'table:supplier',
                        'column' => 'logo_path',
                        'path' => [],
                        'stored_prefix' => 'storage/supplier-logos/',
                    ],
                ],
                'ownership' => ['strategy' => 'database_references'],
                'storage_subdirectory' => 'supplier-logos',
            ],
        );

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
     *   column_codecs?:array<string,string>,
     *   data_columns:list<string>,
     *   derived_hashes?:list<array{
     *     algorithm:string,
     *     dependencies?:list<array{
     *       path:list<string>,
     *       source_hash_column:string
     *     }>,
     *     hash_column:string,
     *     nullable:bool,
     *     source_column:string
     *   }>,
     *   embedded_hash_references?:list<array{
     *     column:string,
     *     nullable:bool,
     *     path:list<string>,
     *     target:string,
     *     target_hash_column:string
     *   }>,
     *   embedded_hashes?:list<array{
     *     algorithm:string,
     *     column:string,
     *     dependencies:list<string>,
     *     hash_path:list<string>,
     *     name:string,
     *     nullable:bool,
     *     omit_paths:list<list<string>>,
     *     projection?:list<array{
     *       key:string,
     *       literal?:string|int|bool|null,
     *       path?:list<string>
     *     }>,
     *     source_path:list<string>
     *   }>,
     *   encoded_references?:list<array<string,mixed>>,
     *   embedded_references:list<array<string,mixed>>,
     *   generated_columns:list<string>,
     *   omit_columns:array<string,string>,
     *   polymorphic_references?:list<array<string,mixed>>,
     *   preserved_identifiers?:list<string>,
     *   protected_secret_materializations?:list<array{
     *     entity_id_column:string,
     *     field:string,
     *     materializer:string,
     *     nullable:bool,
     *     secret_column:string,
     *     target_columns:array{
     *       ciphertext:string,
     *       lookup_hash:string,
     *       masked:string
     *     },
     *     tenant_id_column:string
     *   }>,
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
        if ($table === 'payroll_absences') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollAbsencesProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollAbsencesProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_average_earning_snapshots') {
            return [
                'company_backup' => [
                    'column_codecs' =>
                        CompanyBackupPayrollAverageEarningSnapshotsProjection::columnCodecs(),
                    'data_columns' =>
                        CompanyBackupPayrollAverageEarningSnapshotsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'preserved_identifiers' =>
                        CompanyBackupPayrollAverageEarningSnapshotsProjection::preservedIdentifiers(),
                    'references' =>
                        CompanyBackupPayrollAverageEarningSnapshotsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_business_trips') {
            return [
                'company_backup' => [
                    'column_codecs' =>
                        CompanyBackupPayrollBusinessTripsProjection::columnCodecs(),
                    'data_columns' =>
                        CompanyBackupPayrollBusinessTripsProjection::dataColumns(),
                    'derived_hashes' =>
                        CompanyBackupPayrollBusinessTripsProjection::derivedHashes(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'preserved_identifiers' =>
                        CompanyBackupPayrollBusinessTripsProjection::preservedIdentifiers(),
                    'references' =>
                        CompanyBackupPayrollBusinessTripsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_business_trip_free_meals') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollBusinessTripFreeMealsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollBusinessTripFreeMealsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_business_trip_items') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollBusinessTripItemsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollBusinessTripItemsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_travel_compensation_links') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollTravelCompensationLinksProjection::dataColumns(),
                    'encoded_references' =>
                        CompanyBackupPayrollTravelCompensationLinksProjection::encodedReferences(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollTravelCompensationLinksProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_calendar_days') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollCalendarDaysProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollCalendarDaysProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_component_definitions') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollComponentDefinitionsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollComponentDefinitionsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_deduction_agreement_versions') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollDeductionAgreementVersionsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollDeductionAgreementVersionsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_deduction_agreements') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollDeductionAgreementsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollDeductionAgreementsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_deduction_ledger') {
            return [
                'company_backup' => [
                    'column_codecs' =>
                        CompanyBackupPayrollDeductionLedgerProjection::columnCodecs(),
                    'data_columns' =>
                        CompanyBackupPayrollDeductionLedgerProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollDeductionLedgerProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_dependants') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollDependantsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' =>
                        CompanyBackupPayrollDependantsProjection::omitColumns(),
                    'protected_secret_materializations' =>
                        CompanyBackupPayrollDependantsProjection::protectedSecretMaterializations(),
                    'references' =>
                        CompanyBackupPayrollDependantsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_dimensions') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollDimensionsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollDimensionsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_employee_profiles') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollEmployeeProfilesProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollEmployeeProfilesProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_employees') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollEmployeesProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollEmployeesProjection::references(),
                    'restore_overrides' =>
                        CompanyBackupPayrollEmployeesProjection::restoreOverrides(),
                ],
            ];
        }
        if ($table === 'payroll_employment_checklist_items') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollEmploymentChecklistItemsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollEmploymentChecklistItemsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_employment_dimensions') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollEmploymentDimensionsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollEmploymentDimensionsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_employment_events') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollEmploymentEventsProjection::dataColumns(),
                    'embedded_references' =>
                        CompanyBackupPayrollEmploymentEventsProjection::embeddedReferences(),
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollEmploymentEventsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_employments') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollEmploymentsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' =>
                        CompanyBackupPayrollEmploymentsProjection::generatedColumns(),
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollEmploymentsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_employment_terms') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollEmploymentTermsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollEmploymentTermsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_input_imports') {
            return [
                'company_backup' => [
                    'column_codecs' =>
                        CompanyBackupPayrollInputImportsProjection::columnCodecs(),
                    'data_columns' =>
                        CompanyBackupPayrollInputImportsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollInputImportsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_inputs') {
            return [
                'company_backup' => [
                    'column_codecs' =>
                        CompanyBackupPayrollInputsProjection::columnCodecs(),
                    'data_columns' =>
                        CompanyBackupPayrollInputsProjection::dataColumns(),
                    'derived_hashes' =>
                        CompanyBackupPayrollInputsProjection::derivedHashes(),
                    'encoded_references' =>
                        CompanyBackupPayrollInputsProjection::encodedReferences(),
                    'embedded_references' =>
                        CompanyBackupPayrollInputsProjection::embeddedReferences(),
                    'generated_columns' =>
                        CompanyBackupPayrollInputsProjection::generatedColumns(),
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollInputsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_institution_accounts') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollInstitutionAccountsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' =>
                        CompanyBackupPayrollInstitutionAccountsProjection::omitColumns(),
                    'protected_secret_materializations' =>
                        CompanyBackupPayrollInstitutionAccountsProjection::protectedSecretMaterializations(),
                    'references' =>
                        CompanyBackupPayrollInstitutionAccountsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_institutions') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollInstitutionsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollInstitutionsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_employer_policies') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollEmployerPoliciesProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollEmployerPoliciesProjection::references(),
                    'restore_overrides' =>
                        CompanyBackupPayrollEmployerPoliciesProjection::restoreOverrides(),
                ],
            ];
        }
        if ($table === 'payroll_offices') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollOfficesProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollOfficesProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_overtime_averaging_periods') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollOvertimeAveragingPeriodsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollOvertimeAveragingPeriodsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_overtime_compensations') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollOvertimeCompensationsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollOvertimeCompensationsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_overtime_consents') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollOvertimeConsentsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollOvertimeConsentsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_overtime_protections') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollOvertimeProtectionsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollOvertimeProtectionsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_person_addresses') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollPersonAddressesProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollPersonAddressesProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_person_tax_declarations') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollPersonTaxDeclarationsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollPersonTaxDeclarationsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_person_health_coverage_history') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollPersonHealthCoverageProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollPersonHealthCoverageProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_person_health_minimum_reductions') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollPersonHealthMinimumReductionsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollPersonHealthMinimumReductionsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_person_health_month_evidence') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollPersonHealthMonthEvidenceProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollPersonHealthMonthEvidenceProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_person_health_other_employer_bases') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollPersonHealthOtherEmployerBasesProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollPersonHealthOtherEmployerBasesProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_person_social_jurisdictions') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollPersonSocialJurisdictionsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollPersonSocialJurisdictionsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_person_identity_history') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollPersonIdentityHistoryProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollPersonIdentityHistoryProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_person_social_discount_claims') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollPersonSocialDiscountClaimsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollPersonSocialDiscountClaimsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_person_tax_child_claims') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollPersonTaxChildClaimsProjection::dataColumns(),
                    'encoded_references' =>
                        CompanyBackupPayrollPersonTaxChildClaimsProjection::encodedReferences(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollPersonTaxChildClaimsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_person_tax_credit_claims') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollPersonTaxCreditClaimsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollPersonTaxCreditClaimsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_person_tax_residences') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollPersonTaxResidencesProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollPersonTaxResidencesProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_recurring_components') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollRecurringComponentsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollRecurringComponentsProjection::references(),
                    'restore_overrides' =>
                        CompanyBackupPayrollRecurringComponentsProjection::restoreOverrides(),
                ],
            ];
        }
        if ($table === 'payroll_runs') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollRunsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' =>
                        CompanyBackupPayrollRunsProjection::generatedColumns(),
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollRunsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_run_persons') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollRunPersonsProjection::dataColumns(),
                    'derived_hashes' =>
                        CompanyBackupPayrollRunPersonsProjection::derivedHashes(),
                    'embedded_references' =>
                        CompanyBackupPayrollRunPersonsProjection::embeddedReferences(),
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollRunPersonsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_sickness_events') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollSicknessEventsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'preserved_identifiers' =>
                        CompanyBackupPayrollSicknessEventsProjection::preservedIdentifiers(),
                    'references' =>
                        CompanyBackupPayrollSicknessEventsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_sickness_compensation_segments') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollSicknessCompensationSegmentsProjection::dataColumns(),
                    'embedded_references' =>
                        CompanyBackupPayrollSicknessCompensationSegmentsProjection::embeddedReferences(),
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollSicknessCompensationSegmentsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_shifts') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollShiftsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollShiftsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_time_entries') {
            return [
                'company_backup' => [
                    'column_codecs' =>
                        CompanyBackupPayrollTimeEntriesProjection::columnCodecs(),
                    'data_columns' =>
                        CompanyBackupPayrollTimeEntriesProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollTimeEntriesProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_time_months') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollTimeMonthsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollTimeMonthsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_work_calendars') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollWorkCalendarsProjection::dataColumns(),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollWorkCalendarsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_run_revisions') {
            return [
                'company_backup' => [
                    'column_codecs' =>
                        CompanyBackupPayrollRunRevisionsProjection::columnCodecs(),
                    'data_columns' =>
                        CompanyBackupPayrollRunRevisionsProjection::dataColumns(),
                    'derived_hashes' =>
                        CompanyBackupPayrollRunRevisionsProjection::derivedHashes(),
                    'embedded_hash_references' =>
                        CompanyBackupPayrollRunRevisionsProjection::embeddedHashReferences(),
                    'embedded_hashes' =>
                        CompanyBackupPayrollRunRevisionsProjection::embeddedHashes(),
                    'embedded_references' =>
                        CompanyBackupPayrollRunRevisionsProjection::embeddedReferences(),
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollRunRevisionsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        if ($table === 'payroll_statutory_person_results') {
            return [
                'company_backup' => [
                    'data_columns' =>
                        CompanyBackupPayrollStatutoryPersonResultsProjection::dataColumns(),
                    'derived_hashes' =>
                        CompanyBackupPayrollStatutoryPersonResultsProjection::derivedHashes(),
                    'embedded_hash_references' =>
                        CompanyBackupPayrollStatutoryPersonResultsProjection::embeddedHashReferences(),
                    'embedded_hashes' =>
                        CompanyBackupPayrollStatutoryPersonResultsProjection::embeddedHashes(),
                    'embedded_references' =>
                        CompanyBackupPayrollStatutoryPersonResultsProjection::embeddedReferences(),
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' =>
                        CompanyBackupPayrollStatutoryPersonResultsProjection::references(),
                    'restore_overrides' => [],
                ],
            ];
        }
        $columns = match ($table) {
            'accounting_closing_steps' =>
                CompanyBackupAccountingClosingStepsProjection::dataColumns(),
            'branding_profiles' =>
                CompanyBackupBrandingProfilesProjection::dataColumns(),
            'clients' => CompanyBackupClientsProjection::dataColumns(),
            'countries' => CompanyBackupCountriesProjection::dataColumns(),
            'email_profiles' => CompanyBackupEmailProfilesProjection::dataColumns(),
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
            'pdf_signature_output_settings' =>
                CompanyBackupPdfSignatureOutputSettingsProjection::dataColumns(),
            'projects' => CompanyBackupProjectsProjection::dataColumns(),
            'revenue_categories' =>
                CompanyBackupRevenueCategoriesProjection::dataColumns(),
            'signature_document_overrides' =>
                CompanyBackupSignatureDocumentOverridesProjection::dataColumns(),
            'signature_role_profiles' =>
                CompanyBackupSignatureRoleProfilesProjection::dataColumns(),
            'signing_profiles' =>
                CompanyBackupSigningProfilesProjection::dataColumns(),
            'signing_settings' =>
                CompanyBackupSigningSettingsProjection::dataColumns(),
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
        $polymorphicReferences = match ($table) {
            'journal_entries' =>
                CompanyBackupJournalEntriesProjection::polymorphicReferences(),
            'signature_document_overrides' =>
                CompanyBackupSignatureDocumentOverridesProjection::polymorphicReferences(),
            default => [],
        };
        if ($polymorphicReferences !== []) {
            return [
                'company_backup' => [
                    'data_columns' => $columns,
                    'embedded_references' => $embeddedReferences,
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'polymorphic_references' => $polymorphicReferences,
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
            'email_profiles' => [
                'is_active' => [
                    'value' => 0,
                    'reason' => 'disable_email_delivery_after_restore',
                ],
                'is_default' => [
                    'value' => 0,
                    'reason' => 'require_email_profile_reselection_after_restore',
                ],
            ],
            'pdf_signature_output_settings' => [
                'enabled' => [
                    'value' => 0,
                    'reason' => 'disable_document_signing_after_restore',
                ],
            ],
            'signing_profiles' => [
                'is_active' => [
                    'value' => 0,
                    'reason' => 'disable_document_signing_after_restore',
                ],
            ],
            'signing_settings' => [
                'accountant_profiles_enabled' => [
                    'value' => 0,
                    'reason' => 'disable_personal_signing_profiles_after_restore',
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
            'branding_profiles' =>
                CompanyBackupBrandingProfilesProjection::references(),
            'clients' => CompanyBackupClientsProjection::references(),
            'email_profiles' => CompanyBackupEmailProfilesProjection::references(),
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
            'pdf_signature_output_settings' =>
                CompanyBackupPdfSignatureOutputSettingsProjection::references(),
            'projects' => CompanyBackupProjectsProjection::references(),
            'revenue_categories' =>
                CompanyBackupRevenueCategoriesProjection::references(),
            'signature_document_overrides' =>
                CompanyBackupSignatureDocumentOverridesProjection::references(),
            'signature_role_profiles' =>
                CompanyBackupSignatureRoleProfilesProjection::references(),
            'signing_profiles' =>
                CompanyBackupSigningProfilesProjection::references(),
            'signing_settings' =>
                CompanyBackupSigningSettingsProjection::references(),
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

    /** @return array<string,array<string,string>> */
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
        if ($table === 'payroll_institution_accounts') {
            return CompanyBackupPayrollInstitutionAccountsProjection::secrets();
        }
        if ($table === 'payroll_dependants') {
            return CompanyBackupPayrollDependantsProjection::secrets();
        }
        return [];
    }

    /** @return array<string,array<string,string>> */
    private static function supplierSecretPolicies(): array
    {
        $optional = TenantSecretPolicy::OptionalCredential->value;
        $omit = TenantSecretPolicy::OmitAndReconfigure->value;
        return [
            'idoklad_client_id' => [
                'policy' => $optional,
                'storage' => CompanyBackupSecretStorage::Raw->value,
            ],
            'idoklad_client_secret_enc' => [
                'policy' => $optional,
                'storage' => CompanyBackupSecretStorage::ApplicationEncrypted->value,
            ],
            'idoklad_access_token' => ['policy' => $omit],
            'idoklad_token_expires_at' => ['policy' => $omit],
            'fakturoid_api_key_enc' => [
                'policy' => $optional,
                'storage' => CompanyBackupSecretStorage::ApplicationEncrypted->value,
            ],
            'anthropic_api_key_enc' => [
                'policy' => $optional,
                'storage' => CompanyBackupSecretStorage::ApplicationEncrypted->value,
            ],
            'fakturoid_client_id' => [
                'policy' => $optional,
                'storage' => CompanyBackupSecretStorage::Raw->value,
            ],
            'fakturoid_client_secret_enc' => [
                'policy' => $optional,
                'storage' => CompanyBackupSecretStorage::ApplicationEncrypted->value,
            ],
            'fakturoid_access_token_enc' => ['policy' => $omit],
            'fakturoid_access_token_expires_at' => ['policy' => $omit],
            'azure_openai_api_key_enc' => [
                'policy' => $optional,
                'storage' => CompanyBackupSecretStorage::ApplicationEncrypted->value,
            ],
            'openai_api_key_enc' => [
                'policy' => $optional,
                'storage' => CompanyBackupSecretStorage::ApplicationEncrypted->value,
            ],
            'gemini_api_key_enc' => [
                'policy' => $optional,
                'storage' => CompanyBackupSecretStorage::ApplicationEncrypted->value,
            ],
            'ai_pseudo_salt' => [
                'policy' => TenantSecretPolicy::ProtectedDomainSecret->value,
                'storage' => CompanyBackupSecretStorage::Raw->value,
            ],
        ];
    }
}
