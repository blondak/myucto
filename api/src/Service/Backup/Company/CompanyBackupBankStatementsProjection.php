<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Výpis včetně původních souborů a přenositelné identity navazujícího importu. */
final class CompanyBackupBankStatementsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id', 'supplier_id', 'source', 'source_ref', 'file_name', 'file_hash',
            'file_content', 'pdf_content', 'pdf_name', 'pdf_hash', 'pdf_size_bytes',
            'pdf_uploaded_at', 'account_number', 'bank_code', 'currency',
            'statement_number', 'statement_date', 'prev_balance', 'curr_balance',
            'credit_total', 'debit_total', 'transaction_count', 'matched_count',
            'imported_at', 'imported_by', 'external_identity',
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
                'columns' => ['imported_by'], 'target' => 'table:users',
                'target_columns' => ['id'], 'mapping' => CompanyBackupReferenceMapping::Actor->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['imported_by'], 'fallbacks' => ['null', 'restore_actor'],
            ],
            [
                'columns' => ['supplier_id'], 'target' => 'table:supplier',
                'target_columns' => ['id'], 'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['supplier_id'], 'fallbacks' => [],
            ],
        ];
    }
}
