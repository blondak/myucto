<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

final class CompanyBackupBankMatchSuggestionsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'bank_transaction_id',
            'kind',
            'reason',
            'candidates_json',
            'top_score',
            'margin',
            'deterministic_core',
            'status',
            'accepted_candidate',
            'reviewed_by',
            'reviewed_at',
            'created_at',
            'updated_at',
            'archived_document_references',
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
                'constraint' => 'required',
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['reviewed_by'], 'target' => 'table:users',
                'target_columns' => ['id'], 'mapping' => 'actor',
                'constraint' => 'optional',
                'nullable_columns' => ['reviewed_by'],
                'fallbacks' => ['null', 'restore_actor'],
            ],
            [
                'columns' => ['supplier_id'], 'target' => 'table:supplier',
                'target_columns' => ['id'], 'mapping' => 'tenant_id',
                'constraint' => 'required',
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function embeddedReferences(): array
    {
        return [
            [
                'column' => 'candidates_json', 'path' => ['*', 'invoice_id'],
                'target' => 'table:invoices', 'target_columns' => ['id'],
                'mapping' => 'tenant_id', 'nullable' => true,
                'condition' => null, 'fallbacks' => [],
            ],
            [
                'column' => 'candidates_json', 'path' => ['*', 'invoice_ids', '*'],
                'target' => 'table:invoices', 'target_columns' => ['id'],
                'mapping' => 'tenant_id', 'nullable' => true,
                'condition' => null, 'fallbacks' => [],
            ],
            [
                'column' => 'candidates_json', 'path' => ['*', 'purchase_invoice_id'],
                'target' => 'table:purchase_invoices', 'target_columns' => ['id'],
                'mapping' => 'tenant_id', 'nullable' => true,
                'condition' => null, 'fallbacks' => [],
            ],
        ];
    }
}

