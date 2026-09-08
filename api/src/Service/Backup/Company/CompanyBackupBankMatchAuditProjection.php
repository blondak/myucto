<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

final class CompanyBackupBankMatchAuditProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'bank_transaction_id',
            'decision',
            'kind',
            'invoice_ids',
            'purchase_invoice_id',
            'score',
            'margin',
            'deterministic_core',
            'signals_json',
            'suggestion_id',
            'reverted_at',
            'created_by',
            'created_at',
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
                'columns' => ['created_by'], 'target' => 'table:users',
                'target_columns' => ['id'], 'mapping' => 'actor',
                'constraint' => 'optional',
                'nullable_columns' => ['created_by'],
                'fallbacks' => ['null', 'restore_actor'],
            ],
            [
                'columns' => ['purchase_invoice_id'], 'target' => 'table:purchase_invoices',
                'target_columns' => ['id'], 'mapping' => 'tenant_id',
                'constraint' => 'optional',
                'nullable_columns' => ['purchase_invoice_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['suggestion_id'], 'target' => 'table:bank_match_suggestions',
                'target_columns' => ['id'], 'mapping' => 'tenant_id',
                'constraint' => 'optional',
                'nullable_columns' => ['suggestion_id'],
                'fallbacks' => [],
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
                'column' => 'invoice_ids', 'path' => ['*'],
                'target' => 'table:invoices', 'target_columns' => ['id'],
                'mapping' => 'tenant_id', 'nullable' => true,
                'condition' => null, 'fallbacks' => [],
            ],
        ];
    }
}

