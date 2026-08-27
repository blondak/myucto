<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce uzávěrkových kroků včetně ID uvnitř payloadu. */
final class CompanyBackupAccountingClosingStepsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
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
            [
                'columns' => ['done_by'],
                'target' => 'table:users',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::Actor->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['done_by'],
                'fallbacks' => ['null', 'restore_actor'],
            ],
            [
                'columns' => ['supplier_id', 'period_id'],
                'target' => 'table:accounting_periods',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id'],
                'target' => 'table:supplier',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function embeddedReferences(): array
    {
        return [
            self::tenant(
                ['checks', '*', 'value', 'documented', '*', 'entry_id'],
                'journal_entries',
                conditionPath: ['checks', '*', 'key'],
                conditionEquals: 'transit_261_open',
            ),
            self::tenant(
                ['checks', '*', 'value', 'documented', '*', 'pair_tx_id'],
                'bank_transactions',
                nullable: true,
                conditionPath: ['checks', '*', 'key'],
                conditionEquals: 'transit_261_open',
            ),
            self::tenant(
                ['checks', '*', 'value', 'documented', '*', 'tx_id'],
                'bank_transactions',
                conditionPath: ['checks', '*', 'key'],
                conditionEquals: 'transit_261_open',
            ),
            self::tenant(
                ['checks', '*', 'value', 'findings', '*', 'account_id'],
                'chart_of_accounts',
                nullable: true,
            ),
            self::tenant(
                ['checks', '*', 'value', 'findings', '*', 'doc_id'],
                'assets',
                nullable: true,
                conditionPath: ['checks', '*', 'value', 'findings', '*', 'doc_type'],
                conditionEquals: 'asset',
            ),
            self::tenant(
                ['checks', '*', 'value', 'findings', '*', 'doc_id'],
                'cash_documents',
                nullable: true,
                conditionPath: ['checks', '*', 'value', 'findings', '*', 'doc_type'],
                conditionEquals: 'cash',
            ),
            self::tenant(
                ['checks', '*', 'value', 'findings', '*', 'doc_id'],
                'invoices',
                nullable: true,
                conditionPath: ['checks', '*', 'value', 'findings', '*', 'doc_type'],
                conditionEquals: 'invoice',
            ),
            self::tenant(
                ['checks', '*', 'value', 'findings', '*', 'doc_id'],
                'journal_entries',
                nullable: true,
                conditionPath: ['checks', '*', 'value', 'findings', '*', 'doc_type'],
                conditionEquals: 'journal_entry',
            ),
            self::tenant(
                ['checks', '*', 'value', 'findings', '*', 'doc_id'],
                'purchase_invoices',
                nullable: true,
                conditionPath: ['checks', '*', 'value', 'findings', '*', 'doc_type'],
                conditionEquals: 'purchase_invoice',
            ),
            self::tenant(
                ['checks', '*', 'value', 'findings', '*', 'entry_id'],
                'journal_entries',
                nullable: true,
            ),
            self::tenant(
                ['detail', '*', 'doc_id'],
                'invoices',
                conditionPath: ['detail', '*', 'doc_type'],
                conditionEquals: 'invoice',
            ),
            self::tenant(
                ['detail', '*', 'doc_id'],
                'purchase_invoices',
                conditionPath: ['detail', '*', 'doc_type'],
                conditionEquals: 'purchase_invoice',
            ),
            self::tenant(['entries', '*', 'entry_id'], 'journal_entries'),
            self::tenant(['entries', '*', 'invoice_id'], 'invoices'),
            self::tenant(['entry_id'], 'journal_entries', nullable: true),
            self::tenant(['entry_ids', '*'], 'journal_entries'),
            self::tenant(['fx_reversal_entry_id'], 'journal_entries', nullable: true),
            self::tenant(['next_period_id'], 'accounting_periods'),
            self::tenant(
                ['prepaid_expense_accrual', 'entry_id'],
                'journal_entries',
            ),
            self::tenant(
                ['prepaid_expense_release_entry_id'],
                'journal_entries',
                nullable: true,
            ),
            self::tenant(['reversed', '*', 'entry_id'], 'journal_entries'),
            self::tenant(
                ['reversed', '*', 'reversal_entry_id'],
                'journal_entries',
            ),
            self::tenant(['saldo_lines', '*', 'account_id'], 'chart_of_accounts'),
            self::tenant(['small_asset_accrual', 'entry_id'], 'journal_entries'),
            self::tenant(
                ['small_asset_release_entry_id'],
                'journal_entries',
                nullable: true,
            ),
            self::tenant(
                ['stock_release_entry_id'],
                'journal_entries',
                nullable: true,
            ),
            self::tenant(['unposted_override', 'invoice_ids', '*'], 'invoices'),
            self::actor(['unposted_override', 'overridden_by']),
            self::tenant(
                ['unposted_override', 'purchase_ids', '*'],
                'purchase_invoices',
            ),
            self::tenant(
                ['warnings', '*', 'items', '*', 'id'],
                'purchase_invoices',
                conditionPath: ['warnings', '*', 'key'],
                conditionEquals: 'stock_in_transit',
            ),
            self::tenant(
                ['warnings', '*', 'items', '*', 'id'],
                'stock_documents',
                conditionPath: ['warnings', '*', 'key'],
                conditionEquals: 'stock_unbilled_receipts',
            ),
        ];
    }

    /**
     * @param list<string> $path
     * @param list<string>|null $conditionPath
     * @return array<string,mixed>
     */
    private static function tenant(
        array $path,
        string $target,
        bool $nullable = false,
        ?array $conditionPath = null,
        ?string $conditionEquals = null,
    ): array {
        return [
            'column' => 'payload',
            'condition' => $conditionPath === null ? null : [
                'path' => $conditionPath,
                'equals' => $conditionEquals,
            ],
            'path' => $path,
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'nullable' => $nullable,
            'fallbacks' => [],
        ];
    }

    /**
     * @param list<string> $path
     * @return array<string,mixed>
     */
    private static function actor(array $path): array
    {
        return [
            'column' => 'payload',
            'condition' => null,
            'path' => $path,
            'target' => 'table:users',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::Actor->value,
            'nullable' => true,
            'fallbacks' => ['null', 'restore_actor'],
        ];
    }
}
