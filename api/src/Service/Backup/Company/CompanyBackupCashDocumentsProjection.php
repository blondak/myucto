<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce hlaviček hotovostních dokladů. */
final class CompanyBackupCashDocumentsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'register_id',
            'doc_type',
            'purpose',
            'doc_number',
            'issue_date',
            'tax_date',
            'partner_name',
            'partner_ic',
            'partner_dic',
            'description',
            'vat_mode',
            'total_amount',
            'currency_code',
            'fx_rate',
            'amount_foreign',
            'rule_key',
            'counter_account_code',
            'project_id',
            'invoice_id',
            'purchase_invoice_id',
            'auto_settlement',
            'invoice_payment_id',
            'journal_entry_id',
            'reversal_entry_id',
            'status',
            'created_by',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * Kontace, protiúčet a měna jsou business kódy. Mohou odkazovat na globální
     * šablonu nebo být v daňové evidenci bez účtové osnovy, proto se zachovávají
     * jako hodnoty. Identitní vazby se vždy přemapují.
     *
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
            self::actor('created_by'),
            self::tenant('invoice_id', 'invoices', nullable: true),
            self::tenant(
                'invoice_payment_id',
                'invoice_payments',
                nullable: true,
                constraint: CompanyBackupReferenceConstraint::Optional,
            ),
            self::tenant('journal_entry_id', 'journal_entries', nullable: true),
            self::tenant('project_id', 'projects', nullable: true),
            self::tenant(
                'purchase_invoice_id',
                'purchase_invoices',
                nullable: true,
            ),
            self::tenant('register_id', 'cash_registers'),
            self::tenant(
                'reversal_entry_id',
                'journal_entries',
                nullable: true,
                constraint: CompanyBackupReferenceConstraint::Optional,
            ),
            self::tenant('supplier_id', 'supplier'),
        ];
    }

    /**
     * @return array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private static function tenant(
        string $column,
        string $target,
        bool $nullable = false,
        CompanyBackupReferenceConstraint $constraint = CompanyBackupReferenceConstraint::Required,
    ): array {
        return [
            'columns' => [$column],
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => $constraint->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => [],
        ];
    }

    /**
     * @return array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,
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
}
