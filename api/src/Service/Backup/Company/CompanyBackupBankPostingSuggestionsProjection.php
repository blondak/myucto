<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

final class CompanyBackupBankPostingSuggestionsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'bank_transaction_id',
            'rule_id',
            'source',
            'debit_account_code',
            'credit_account_code',
            'amount',
            'description',
            'status',
            'note',
            'journal_entry_id',
            'reviewed_by',
            'reviewed_at',
            'created_at',
            'confidence',
            'detector',
            'operation_type',
            'tax_advance_schedule_id',
            'ai_reasoning',
            'ai_model',
            'ai_provider',
            'ai_prompt_version',
            'batch_id',
            'snoozed_until',
            'snooze_reason',
            'snoozed_by',
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
                'columns' => ['bank_transaction_id'], 'target' => 'table:bank_transactions',
                'target_columns' => ['id'], 'mapping' => 'tenant_id',
                'constraint' => 'required', 'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['journal_entry_id'], 'target' => 'table:journal_entries',
                'target_columns' => ['id'], 'mapping' => 'tenant_id',
                'constraint' => 'required', 'nullable_columns' => ['journal_entry_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['reviewed_by'], 'target' => 'table:users',
                'target_columns' => ['id'], 'mapping' => 'actor',
                'constraint' => 'required', 'nullable_columns' => ['reviewed_by'],
                'fallbacks' => ['null', 'restore_actor'],
            ],
            [
                'columns' => ['rule_id'], 'target' => 'table:bank_posting_rules',
                'target_columns' => ['id'], 'mapping' => 'tenant_id',
                'constraint' => 'required', 'nullable_columns' => ['rule_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['snoozed_by'], 'target' => 'table:users',
                'target_columns' => ['id'], 'mapping' => 'actor',
                'constraint' => 'required', 'nullable_columns' => ['snoozed_by'],
                'fallbacks' => ['null', 'restore_actor'],
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
                'columns' => ['tax_advance_schedule_id'], 'target' => 'table:tax_advance_schedules',
                'target_columns' => ['id'], 'mapping' => 'tenant_id',
                'constraint' => 'required', 'nullable_columns' => ['tax_advance_schedule_id'],
                'fallbacks' => [],
            ],
        ];
    }
}

