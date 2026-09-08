<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

final class CompanyBackupBankPostingRulesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'name',
            'direction',
            'counterparty_account',
            'counterparty_bank',
            'variable_symbol',
            'message_contains',
            'amount_min',
            'amount_max',
            'debit_account_code',
            'credit_account_code',
            'description',
            'mode',
            'is_active',
            'hit_count',
            'last_hit_at',
            'rejected_streak',
            'last_rejected_tx_id',
            'created_by',
            'created_at',
            'updated_at',
            'priority',
            'operation_type',
            'system_template_key',
            'auto_amount_cap',
            'applies_currency',
            'counterparty_prefix',
            'approved_streak',
            'archived_rejected_transactions',
        ];
    }

    /**
     * @return list<array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,fallbacks:list<string>
     * }>
     */
    public static function references(): array
    {
        return [
            [
                'columns' => ['created_by'], 'target' => 'table:users',
                'target_columns' => ['id'], 'mapping' => 'actor',
                'constraint' => 'required', 'nullable_columns' => ['created_by'],
                'fallbacks' => ['null', 'restore_actor'],
            ],
            [
                'columns' => ['last_rejected_tx_id'], 'target' => 'table:bank_transactions',
                'target_columns' => ['id'], 'mapping' => 'tenant_id',
                'constraint' => 'optional', 'nullable_columns' => ['last_rejected_tx_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id', 'credit_account_code'], 'target' => 'table:chart_of_accounts',
                'target_columns' => ['supplier_id', 'account_code'], 'mapping' => 'tenant_natural_key',
                'constraint' => 'optional', 'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id', 'debit_account_code'], 'target' => 'table:chart_of_accounts',
                'target_columns' => ['supplier_id', 'account_code'], 'mapping' => 'tenant_natural_key',
                'constraint' => 'optional', 'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id'], 'target' => 'table:supplier',
                'target_columns' => ['id'], 'mapping' => 'tenant_id',
                'constraint' => 'required', 'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['system_template_key'], 'target' => 'table:bank_rule_templates',
                'target_columns' => ['template_key'], 'mapping' => 'global_natural_key',
                'constraint' => 'optional', 'nullable_columns' => ['system_template_key'],
                'fallbacks' => [],
            ],
        ];
    }
}
