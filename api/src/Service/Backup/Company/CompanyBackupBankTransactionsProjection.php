<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Historické pohyby; tenantový dedup_scope_id obnoví trigger z nové hlavičky. */
final class CompanyBackupBankTransactionsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id', 'source', 'source_ref', 'statement_id', 'posted_at', 'amount',
            'balance', 'currency', 'variable_symbol', 'constant_symbol', 'specific_symbol',
            'counterparty_account', 'counterparty_bank', 'counterparty_name', 'description',
            'bank_ref', 'import_fingerprint', 'matched_invoice_id', 'match_status',
            'match_tolerance', 'matched_at', 'matched_by', 'external_identity',
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
                'columns' => ['matched_by'], 'target' => 'table:users',
                'target_columns' => ['id'], 'mapping' => CompanyBackupReferenceMapping::Actor->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['matched_by'], 'fallbacks' => ['null', 'restore_actor'],
            ],
            [
                'columns' => ['matched_invoice_id'], 'target' => 'table:invoices',
                'target_columns' => ['id'], 'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['matched_invoice_id'], 'fallbacks' => [],
            ],
            [
                'columns' => ['statement_id'], 'target' => 'table:bank_statements',
                'target_columns' => ['id'], 'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [], 'fallbacks' => [],
            ],
        ];
    }
}
