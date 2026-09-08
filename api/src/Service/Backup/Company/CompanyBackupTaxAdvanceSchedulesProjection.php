<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

final class CompanyBackupTaxAdvanceSchedulesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'taxpayer_type',
            'advance_kind',
            'period_year',
            'seq_no',
            'amount',
            'due_date',
            'variable_symbol',
            'status',
            'paid_amount',
            'paid_on',
            'matched_transaction_id',
            'match_confidence',
            'paid_source',
            'source_return_id',
            'created_at',
            'updated_at',
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
                'columns' => ['matched_transaction_id'], 'target' => 'table:bank_transactions',
                'target_columns' => ['id'], 'mapping' => 'tenant_id',
                'constraint' => 'required', 'nullable_columns' => ['matched_transaction_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['source_return_id'], 'target' => 'table:income_tax_returns',
                'target_columns' => ['id'], 'mapping' => 'tenant_id',
                'constraint' => 'optional', 'nullable_columns' => ['source_return_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id'], 'target' => 'table:supplier',
                'target_columns' => ['id'], 'mapping' => 'tenant_id',
                'constraint' => 'required', 'nullable_columns' => [],
                'fallbacks' => [],
            ],
        ];
    }
}

