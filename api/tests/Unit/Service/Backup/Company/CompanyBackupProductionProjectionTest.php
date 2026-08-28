<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupPolymorphicReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupPolymorphicReferenceTransform;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupProductionProjectionTest extends TestCase
{
    public function testCurrenciesHaveFirstExplicitProductionColumnProjection(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:currencies');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
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
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame([], $projection->generatedColumns);
        self::assertSame([], $projection->omitColumns);
        self::assertNull($projection->requiredSecretEnvelopeColumn());
        self::assertCount(1, $projection->references->references);
        self::assertSame(
            CompanyBackupReferenceMapping::TenantId,
            $projection->references->references[0]->mapping,
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Required,
            $projection->references->references[0]->constraint,
        );
        $projection->references->assertRegistryTargets(TenantDataRegistryFactory::draftV1());
    }

    public function testAccountingPeriodsDeclareNullableHistoricalActors(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:accounting_periods');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
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
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['approved_by', 'closed_by', 'reviewed_by'],
            [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            ['approved_by', 'closed_by', 'reviewed_by', 'supplier_id'],
            array_map(
                static fn ($reference): string => $reference->firstColumn(),
                $projection->references->references,
            ),
        );
        foreach (array_slice($projection->references->references, 0, 3) as $actor) {
            self::assertSame(CompanyBackupReferenceMapping::Actor, $actor->mapping);
            self::assertSame(['null', 'restore_actor'], $actor->fallbacks);
        }

        $users = $registry->definition('table:users');
        self::assertNotNull($users);
        self::assertSame(TenantDataPolicy::InstanceOwned, $users->policy);
        self::assertFalse($users->policy->hasMachineDataPayload());
    }

    public function testChartOfAccountsDeclaresNullableSelfReference(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:chart_of_accounts');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
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
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['parent_id'],
            [
                new CompanyBackupForeignKey(['parent_id'], 'chart_of_accounts', ['id']),
                new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
            ],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            ['parent_id', 'supplier_id'],
            array_map(
                static fn ($reference): string => $reference->firstColumn(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            ['parent_id'],
            $projection->references->references[0]->nullableColumns,
        );
    }

    public function testPostingRulesDeclareTenantNaturalKeyReferences(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:posting_rules');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'rule_key',
            'description',
            'debit_account_code',
            'credit_account_code',
            'priority',
            'is_active',
            'created_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['supplier_id', 'debit_account_code', 'credit_account_code'],
            [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            [
                'supplier_id,credit_account_code->chart_of_accounts:supplier_id,account_code',
                'supplier_id,debit_account_code->chart_of_accounts:supplier_id,account_code',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            CompanyBackupReferenceMapping::TenantNaturalKey,
            $projection->references->references[0]->mapping,
        );
    }

    public function testCostCentersDeclareSupplierReference(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:cost_centers');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'code',
            'name',
            'is_active',
            'created_at',
            'updated_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            [],
            [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertCount(1, $projection->references->references);
    }

    public function testAccountingSupplierSettingsDisableAutomationOnRestore(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:accounting_supplier_settings');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
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
        ];

        $projection->assertRuntimeSchema($columns, [], ['supplier_id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['fuel_account_code', 'vehicle_repair_account_code'],
            [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
        ));
        $restored = $projection->restoreOverrides->apply([
            'supplier_id' => 9,
            'automation_level' => 'full',
            'automation_digest_enabled' => 1,
        ]);

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame('off', $restored['automation_level']);
        self::assertSame(0, $restored['automation_digest_enabled']);
        self::assertSame(
            [
                'supplier_id,fuel_account_code->chart_of_accounts:supplier_id,account_code',
                'supplier_id,vehicle_repair_account_code'
                    . '->chart_of_accounts:supplier_id,account_code',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
    }

    public function testAccountingDocumentSeriesDeclaresZeroSentinelRegister(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:accounting_document_series');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
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
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['number_format'],
            [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            [
                'register_id->cash_registers:id',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            CompanyBackupReferenceMapping::TenantIdOrZero,
            $projection->references->references[0]->mapping,
        );
    }

    public function testAccountingClosingStepsDeclareEveryPayloadReference(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:accounting_closing_steps',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'period_id',
            'step_key',
            'status',
            'payload',
            'note',
            'done_at',
            'done_by',
            'created_at',
            'updated_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['payload', 'note', 'done_at', 'done_by'],
            [
                new CompanyBackupForeignKey(
                    ['supplier_id', 'period_id'],
                    'accounting_periods',
                    ['supplier_id', 'id'],
                ),
                new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
            ],
        ));
        $projection->embeddedReferences->assertRegistryTargets($registry);

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            [
                'done_by->users:id',
                'supplier_id,period_id->accounting_periods:supplier_id,id',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            [
                'payload:checks.*.value.documented.*.entry_id->journal_entries:id'
                    . '?checks.*.key=transit_261_open',
                'payload:checks.*.value.documented.*.pair_tx_id->bank_transactions:id'
                    . '?checks.*.key=transit_261_open',
                'payload:checks.*.value.documented.*.tx_id->bank_transactions:id'
                    . '?checks.*.key=transit_261_open',
                'payload:checks.*.value.findings.*.account_id->chart_of_accounts:id',
                'payload:checks.*.value.findings.*.doc_id->assets:id'
                    . '?checks.*.value.findings.*.doc_type=asset',
                'payload:checks.*.value.findings.*.doc_id->cash_documents:id'
                    . '?checks.*.value.findings.*.doc_type=cash',
                'payload:checks.*.value.findings.*.doc_id->invoices:id'
                    . '?checks.*.value.findings.*.doc_type=invoice',
                'payload:checks.*.value.findings.*.doc_id->journal_entries:id'
                    . '?checks.*.value.findings.*.doc_type=journal_entry',
                'payload:checks.*.value.findings.*.doc_id->purchase_invoices:id'
                    . '?checks.*.value.findings.*.doc_type=purchase_invoice',
                'payload:checks.*.value.findings.*.entry_id->journal_entries:id',
                'payload:detail.*.doc_id->invoices:id?detail.*.doc_type=invoice',
                'payload:detail.*.doc_id->purchase_invoices:id'
                    . '?detail.*.doc_type=purchase_invoice',
                'payload:entries.*.entry_id->journal_entries:id',
                'payload:entries.*.invoice_id->invoices:id',
                'payload:entry_id->journal_entries:id',
                'payload:entry_ids.*->journal_entries:id',
                'payload:fx_reversal_entry_id->journal_entries:id',
                'payload:next_period_id->accounting_periods:id',
                'payload:prepaid_expense_accrual.entry_id->journal_entries:id',
                'payload:prepaid_expense_release_entry_id->journal_entries:id',
                'payload:reversed.*.entry_id->journal_entries:id',
                'payload:reversed.*.reversal_entry_id->journal_entries:id',
                'payload:saldo_lines.*.account_id->chart_of_accounts:id',
                'payload:small_asset_accrual.entry_id->journal_entries:id',
                'payload:small_asset_release_entry_id->journal_entries:id',
                'payload:stock_release_entry_id->journal_entries:id',
                'payload:unposted_override.invoice_ids.*->invoices:id',
                'payload:unposted_override.overridden_by->users:id',
                'payload:unposted_override.purchase_ids.*->purchase_invoices:id',
                'payload:warnings.*.items.*.id->purchase_invoices:id'
                    . '?warnings.*.key=stock_in_transit',
                'payload:warnings.*.items.*.id->stock_documents:id'
                    . '?warnings.*.key=stock_unbilled_receipts',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->embeddedReferences->references,
            ),
        );
    }

    public function testJournalEntriesDeclareEverySourceIdVariant(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:journal_entries');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'period_id',
            'entry_date',
            'document_date',
            'document_no',
            'description',
            'source_type',
            'source_id',
            'posted_at',
            'posted_by',
            'reversed_by',
            'row_version',
            'created_at',
            'updated_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            [
                'document_date',
                'document_no',
                'description',
                'source_id',
                'posted_at',
                'posted_by',
                'reversed_by',
            ],
            [
                new CompanyBackupForeignKey(
                    ['supplier_id', 'period_id'],
                    'accounting_periods',
                    ['supplier_id', 'id'],
                ),
                new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
                new CompanyBackupForeignKey(['posted_by'], 'users', ['id']),
                new CompanyBackupForeignKey(
                    ['reversed_by'],
                    'journal_entries',
                    ['id'],
                ),
            ],
        ));
        $projection->polymorphicReferences->assertRegistryTargets($registry);

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            [
                'posted_by->users:id',
                'reversed_by->journal_entries:id',
                'supplier_id,period_id->accounting_periods:supplier_id,id',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );

        $polymorphic = $projection->polymorphicReferences->references;
        self::assertCount(1, $polymorphic);
        $cases = $polymorphic[0]->cases;
        self::assertSame(
            [
                'asset',
                'asset_disposal',
                'bank',
                'cash',
                'closing',
                'deferred_tax',
                'depreciation',
                'fx_revaluation',
                'income_tax',
                'invoice',
                'manual',
                'offset',
                'opening',
                'payroll',
                'prepaid_expense_accrual',
                'profit_distribution',
                'provision',
                'purchase_invoice',
                'settlement',
                'small_asset_accrual',
                'stock',
                'vat_clearing',
            ],
            array_map(static fn ($case): string => $case->equals, $cases),
        );

        $byType = [];
        foreach ($cases as $case) {
            $byType[$case->equals] = $case;
        }
        self::assertSame('table:invoices', $byType['invoice']->target);
        self::assertSame('table:invoices', $byType['provision']->target);
        self::assertSame('table:payroll_run_revisions', $byType['payroll']->target);
        self::assertSame(
            CompanyBackupPolymorphicReferenceMapping::Preserve,
            $byType['manual']->mapping,
        );
        self::assertSame(
            CompanyBackupPolymorphicReferenceMapping::Preserve,
            $byType['vat_clearing']->mapping,
        );
        self::assertSame(
            CompanyBackupPolymorphicReferenceTransform::IdentityOrDecimalSlot,
            $byType['closing']->transform,
        );
        self::assertSame(
            CompanyBackupPolymorphicReferenceTransform::DecimalSlot,
            $byType['fx_revaluation']->transform,
        );
        self::assertSame(
            CompanyBackupPolymorphicReferenceTransform::IdentityOrOffset,
            $byType['small_asset_accrual']->transform,
        );
    }

    public function testJournalEntryLinesDeclareDimensionsAndTenantReferences(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:journal_entry_lines');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'entry_id',
            'supplier_id',
            'account_id',
            'side',
            'amount',
            'currency_code',
            'fx_rate',
            'amount_foreign',
            'cost_center',
            'project_id',
            'line_no',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['currency_code', 'fx_rate', 'amount_foreign', 'cost_center', 'project_id'],
            [
                new CompanyBackupForeignKey(
                    ['supplier_id', 'account_id'],
                    'chart_of_accounts',
                    ['supplier_id', 'id'],
                ),
                new CompanyBackupForeignKey(
                    ['supplier_id', 'entry_id'],
                    'journal_entries',
                    ['supplier_id', 'id'],
                ),
                new CompanyBackupForeignKey(['project_id'], 'projects', ['id']),
                new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
            ],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            [
                'project_id->projects:id',
                'supplier_id,account_id->chart_of_accounts:supplier_id,id',
                'supplier_id,cost_center->cost_centers:supplier_id,code',
                'supplier_id,entry_id->journal_entries:supplier_id,id',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        $costCenterReference = array_values(array_filter(
            $projection->references->references,
            static fn ($reference): bool =>
                $reference->columns === ['supplier_id', 'cost_center'],
        ))[0] ?? null;
        self::assertNotNull($costCenterReference);
        self::assertSame(
            CompanyBackupReferenceMapping::TenantNaturalKey,
            $costCenterReference->mapping,
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Optional,
            $costCenterReference->constraint,
        );
        foreach ($projection->references->references as $reference) {
            self::assertNotContains('currency_code', $reference->columns);
        }

        $projects = $registry->definition('table:projects');
        self::assertNotNull($projects);
        self::assertSame(TenantDataPolicy::TenantOwnedIndirect, $projects->policy);
        self::assertSame('foreign_key_path', $projects->details['ownership']['strategy'] ?? null);
    }

    public function testProjectsDeclareIndirectOwnershipAndBusinessReferences(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:projects');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'client_id',
            'name',
            'payment_due_days',
            'billing_emails_mode',
            'payment_due_unit',
            'project_number',
            'contract_number',
            'budget_total',
            'budget_yearly',
            'budget_monthly',
            'hourly_rate',
            'currency_id',
            'status',
            'requires_work_report_approval',
            'note',
            'default_revenue_category_id',
            'archived_at',
            'created_at',
            'updated_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            [
                'payment_due_unit',
                'project_number',
                'contract_number',
                'budget_total',
                'budget_yearly',
                'budget_monthly',
                'note',
                'default_revenue_category_id',
                'archived_at',
            ],
            [
                new CompanyBackupForeignKey(['client_id'], 'clients', ['id']),
                new CompanyBackupForeignKey(['currency_id'], 'currencies', ['id']),
            ],
        ));

        self::assertSame(TenantDataPolicy::TenantOwnedIndirect, $projection->policy);
        self::assertSame('foreign_key_path', $projection->ownership['strategy'] ?? null);
        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            [
                'client_id->clients:id',
                'currency_id->currencies:id',
                'default_revenue_category_id->revenue_categories:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Optional,
            $projection->references->references[2]->constraint,
        );
    }

    public function testRevenueCategoriesDeclareNaturalKeyAndSupplierReference(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:revenue_categories');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'code',
            'label',
            'display_order',
            'invoice_number_format',
            'proforma_number_format',
            'credit_note_number_format',
            'invoice_number_period',
            'archived',
            'created_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            [
                'invoice_number_format',
                'proforma_number_format',
                'credit_note_number_format',
                'invoice_number_period',
            ],
            [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'code'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            ['supplier_id->supplier:id'],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
    }

    public function testAccountingClosingPayloadReferencesUseTheirDeclaredTargets(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition(
            'table:accounting_closing_steps',
        );
        self::assertNotNull($definition);
        $references = CompanyBackupTableProjection::fromDefinition($definition)
            ->embeddedReferences;
        $offsets = [
            'table:assets' => 1000,
            'table:bank_transactions' => 2000,
            'table:chart_of_accounts' => 3000,
            'table:cash_documents' => 4000,
            'table:invoices' => 5000,
            'table:journal_entries' => 6000,
            'table:purchase_invoices' => 7000,
            'table:accounting_periods' => 8000,
            'table:users' => 9000,
            'table:stock_documents' => 10000,
        ];
        $payload = [
            'checks' => [[
                'key' => 'transit_261_open',
                'value' => [
                    'documented' => [
                        ['entry_id' => 1, 'tx_id' => 2, 'pair_tx_id' => null],
                        ['entry_id' => 3, 'tx_id' => 4, 'pair_tx_id' => 5],
                    ],
                    'findings' => [
                        ['account_id' => 6],
                        ['doc_type' => 'asset', 'doc_id' => 7, 'entry_id' => 8],
                        ['doc_type' => 'cash', 'doc_id' => 9],
                        ['doc_type' => 'invoice', 'doc_id' => 10],
                        ['doc_type' => 'journal_entry', 'doc_id' => 11],
                        ['doc_type' => 'purchase_invoice', 'doc_id' => 12],
                    ],
                ],
            ]],
            'detail' => [
                ['doc_type' => 'invoice', 'doc_id' => 13],
                ['doc_type' => 'purchase_invoice', 'doc_id' => 14],
            ],
            'entries' => [['entry_id' => 15, 'invoice_id' => 16]],
            'entry_id' => 17,
            'entry_ids' => ['saldo' => 18, 'bank' => 19],
            'fx_reversal_entry_id' => null,
            'next_period_id' => 20,
            'prepaid_expense_accrual' => ['entry_id' => 21],
            'prepaid_expense_release_entry_id' => 22,
            'reversed' => [['entry_id' => 23, 'reversal_entry_id' => 24]],
            'saldo_lines' => [['account_id' => 25]],
            'small_asset_accrual' => ['entry_id' => 26],
            'small_asset_release_entry_id' => 27,
            'stock_release_entry_id' => 28,
            'unposted_override' => [
                'invoice_ids' => [29, 30],
                'overridden_by' => 31,
                'purchase_ids' => [32],
            ],
            'warnings' => [
                ['key' => 'stock_unbilled_receipts', 'items' => [['id' => 33]]],
                ['key' => 'stock_in_transit', 'items' => [['id' => 34]]],
            ],
        ];

        $restored = $references->remap(
            ['payload' => $payload],
            static fn (
                CompanyBackupEmbeddedReference $reference,
                int|string $value,
            ): int => (int) $value + $offsets[$reference->target],
        )['payload'];

        self::assertSame(6001, $restored['checks'][0]['value']['documented'][0]['entry_id']);
        self::assertSame(2002, $restored['checks'][0]['value']['documented'][0]['tx_id']);
        self::assertNull($restored['checks'][0]['value']['documented'][0]['pair_tx_id']);
        self::assertSame(2005, $restored['checks'][0]['value']['documented'][1]['pair_tx_id']);
        self::assertSame(3006, $restored['checks'][0]['value']['findings'][0]['account_id']);
        self::assertSame(1007, $restored['checks'][0]['value']['findings'][1]['doc_id']);
        self::assertSame(6008, $restored['checks'][0]['value']['findings'][1]['entry_id']);
        self::assertSame(4009, $restored['checks'][0]['value']['findings'][2]['doc_id']);
        self::assertSame(5010, $restored['checks'][0]['value']['findings'][3]['doc_id']);
        self::assertSame(6011, $restored['checks'][0]['value']['findings'][4]['doc_id']);
        self::assertSame(7012, $restored['checks'][0]['value']['findings'][5]['doc_id']);
        self::assertSame(5013, $restored['detail'][0]['doc_id']);
        self::assertSame(7014, $restored['detail'][1]['doc_id']);
        self::assertSame(6015, $restored['entries'][0]['entry_id']);
        self::assertSame(5016, $restored['entries'][0]['invoice_id']);
        self::assertSame(6017, $restored['entry_id']);
        self::assertSame(['saldo' => 6018, 'bank' => 6019], $restored['entry_ids']);
        self::assertNull($restored['fx_reversal_entry_id']);
        self::assertSame(8020, $restored['next_period_id']);
        self::assertSame(6021, $restored['prepaid_expense_accrual']['entry_id']);
        self::assertSame(6022, $restored['prepaid_expense_release_entry_id']);
        self::assertSame(6023, $restored['reversed'][0]['entry_id']);
        self::assertSame(6024, $restored['reversed'][0]['reversal_entry_id']);
        self::assertSame(3025, $restored['saldo_lines'][0]['account_id']);
        self::assertSame(6026, $restored['small_asset_accrual']['entry_id']);
        self::assertSame(6027, $restored['small_asset_release_entry_id']);
        self::assertSame(6028, $restored['stock_release_entry_id']);
        self::assertSame([5029, 5030], $restored['unposted_override']['invoice_ids']);
        self::assertSame(9031, $restored['unposted_override']['overridden_by']);
        self::assertSame([7032], $restored['unposted_override']['purchase_ids']);
        self::assertSame(10033, $restored['warnings'][0]['items'][0]['id']);
        self::assertSame(7034, $restored['warnings'][1]['items'][0]['id']);
    }
}
