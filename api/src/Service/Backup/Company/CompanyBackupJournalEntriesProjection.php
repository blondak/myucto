<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Accounting\Closing\ClosingSourceId;

/** Úplná company projekce hlaviček účetního deníku včetně source_id variant. */
final class CompanyBackupJournalEntriesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
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
    public static function references(): array
    {
        return [
            self::actor('posted_by'),
            self::tenant('reversed_by', 'journal_entries', nullable: true),
            [
                'columns' => ['supplier_id', 'period_id'],
                'target' => 'table:accounting_periods',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            self::tenant('supplier_id', 'supplier'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function polymorphicReferences(): array
    {
        return [[
            'column' => 'source_id',
            'discriminator_column' => 'source_type',
            'nullable' => true,
            'cases' => [
                self::tenantCase('asset', 'assets'),
                self::tenantCase('asset_disposal', 'assets'),
                self::tenantCase('bank', 'bank_transactions'),
                self::tenantCase('cash', 'cash_documents'),
                self::tenantCase(
                    'closing',
                    'accounting_periods',
                    CompanyBackupPolymorphicReferenceTransform::IdentityOrDecimalSlot,
                    ClosingSourceId::STOCK_SLOT_BASE,
                    10,
                    [
                        ClosingSourceId::SLOT_STOCK_CLOSING,
                        ClosingSourceId::SLOT_STOCK_SHORTAGE,
                        ClosingSourceId::SLOT_STOCK_SURPLUS,
                        ClosingSourceId::SLOT_STOCK_OPENING,
                    ],
                ),
                self::tenantCase('deferred_tax', 'accounting_periods'),
                self::tenantCase('depreciation', 'depreciation_entries'),
                self::tenantCase(
                    'fx_revaluation',
                    'accounting_periods',
                    CompanyBackupPolymorphicReferenceTransform::DecimalSlot,
                    multiplier: 10,
                    slots: [
                        ClosingSourceId::SLOT_FX_SALDO,
                        ClosingSourceId::SLOT_FX_BANK,
                        ClosingSourceId::SLOT_FX_REVERSAL,
                    ],
                ),
                self::tenantCase('income_tax', 'accounting_periods'),
                self::tenantCase('invoice', 'invoices'),
                self::preserveCase('manual'),
                self::tenantCase('offset', 'offset_agreements'),
                self::tenantCase(
                    'opening',
                    'accounting_periods',
                    CompanyBackupPolymorphicReferenceTransform::IdentityOrDecimalSlot,
                    ClosingSourceId::STOCK_SLOT_BASE,
                    10,
                    [ClosingSourceId::SLOT_STOCK_OPENING],
                ),
                self::tenantCase('payroll', 'payroll_run_revisions'),
                self::tenantCase(
                    'prepaid_expense_accrual',
                    'accounting_periods',
                    CompanyBackupPolymorphicReferenceTransform::IdentityOrOffset,
                    ClosingSourceId::PREPAID_EXPENSE_RELEASE_BASE,
                ),
                self::tenantCase('profit_distribution', 'accounting_periods'),
                self::tenantCase('provision', 'invoices'),
                self::tenantCase('purchase_invoice', 'purchase_invoices'),
                self::tenantCase('settlement', 'invoice_settlements'),
                self::tenantCase(
                    'small_asset_accrual',
                    'accounting_periods',
                    CompanyBackupPolymorphicReferenceTransform::IdentityOrOffset,
                    ClosingSourceId::SMALL_ASSET_RELEASE_BASE,
                ),
                self::tenantCase('stock', 'accounting_periods'),
                self::preserveCase('vat_clearing'),
            ],
        ]];
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
    private static function tenant(
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
    private static function actor(string $column): array
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

    /**
     * @param list<int> $slots
     * @return array<string,mixed>
     */
    private static function tenantCase(
        string $equals,
        string $target,
        CompanyBackupPolymorphicReferenceTransform $transform =
            CompanyBackupPolymorphicReferenceTransform::Identity,
        int $base = 0,
        int $multiplier = 1,
        array $slots = [],
    ): array {
        return [
            'base' => $base,
            'equals' => $equals,
            'mapping' => CompanyBackupPolymorphicReferenceMapping::TenantId->value,
            'multiplier' => $multiplier,
            'slots' => $slots,
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'transform' => $transform->value,
        ];
    }

    /** @return array<string,mixed> */
    private static function preserveCase(string $equals): array
    {
        return [
            'base' => 0,
            'equals' => $equals,
            'mapping' => CompanyBackupPolymorphicReferenceMapping::Preserve->value,
            'multiplier' => 1,
            'slots' => [],
            'target' => null,
            'target_columns' => [],
            'transform' => CompanyBackupPolymorphicReferenceTransform::Identity->value,
        ];
    }
}
