<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Kontrakt hlaviček přijatých faktur. Před aktivací zbývají související
 * závislosti a souborové cesty PDF a zdroje.
 */
final class CompanyBackupPurchaseInvoicesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id', 'supplier_id', 'vendor_id', 'vendor_is_vat_payer',
            'varsymbol', 'vendor_invoice_number', 'document_kind',
            'advance_purchase_invoice_id', 'advance_link_suggested_id',
            'issue_date', 'tax_date', 'due_date', 'received_at',
            'import_batch_id', 'currency_id', 'exchange_rate',
            'exchange_rate_date', 'exchange_rate_source', 'reverse_charge',
            'language', 'note_above_items', 'note_below_items',
            'vendor_snapshot', 'own_snapshot', 'total_without_vat',
            'total_vat', 'total_with_vat', 'rounding', 'advance_paid_amount',
            'parent_purchase_invoice_id', 'payment_currency_id',
            'payment_exchange_rate', 'paid_amount_payment_ccy',
            'paid_amount_invoice_ccy', 'exchange_diff_base',
            'payment_account_number', 'payment_bank_code', 'payment_iban',
            'payment_bic', 'payment_variable_symbol',
            'payment_constant_symbol', 'payment_method',
            'payment_method_source', 'cash_register_id',
            'payment_account_source', 'payment_account_checked_at',
            'payment_ordered_at', 'status', 'booked_at', 'booked_by',
            'paid_at', 'cancelled_at', 'pdf_path', 'pdf_hash',
            'pdf_size_bytes', 'pdf_original_name', 'pdf_uploaded_at',
            'source_path', 'source_hash', 'source_size_bytes',
            'source_original_name', 'source_format', 'source_uploaded_at',
            'vat_classification_code', 'vat_deduction',
            'vat_deduction_percent', 'tax_deductible', 'is_fixed_asset',
            'created_by', 'created_at', 'updated_at', 'idoklad_id',
            'fakturoid_id', 'expense_category_id', 'project_id',
            'extraction_warning', 'prices_include_vat', 'vat_overrides',
            'received_at_source',
        ];
    }

    /** @return list<string> */
    public static function generatedColumns(): array
    {
        return ['amount_to_pay', 'effective_cost_date'];
    }

    /** @return list<string> */
    public static function preservedIdentifiers(): array
    {
        // Migrace 0141: import_batch_id je náhodný identifikátor dávky;
        // repository podle něj seskupuje doklady daného dodavatele.
        return ['idoklad_id', 'fakturoid_id', 'import_batch_id'];
    }

    /** @return list<array<string,mixed>> */
    public static function references(): array
    {
        return [
            self::tenant('advance_link_suggested_id', 'purchase_invoices', true),
            self::tenant('advance_purchase_invoice_id', 'purchase_invoices', true),
            self::actor('booked_by', true),
            self::tenant('cash_register_id', 'cash_registers', true),
            self::actor('created_by'),
            self::tenant('currency_id', 'currencies'),
            self::tenant('expense_category_id', 'expense_categories', true,
                CompanyBackupReferenceConstraint::Optional),
            self::tenant('parent_purchase_invoice_id', 'purchase_invoices', true),
            self::tenant('payment_currency_id', 'currencies', true),
            self::tenant('project_id', 'projects', true),
            self::tenant('supplier_id', 'supplier'),
            self::tenant('vendor_id', 'clients'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function embeddedReferences(): array
    {
        // PurchaseInvoiceRepository::update() mění vendor_id, snapshot ale ponechává.
        // vendor_snapshot.id je tedy historický marker; own_snapshot je rovněž
        // historický obsah bez živé vazby. Na rozdíl od invoices.supplier_snapshot
        // tu žádné ID neřídí runtime lookup. Aktuální vazby mapuje references().
        return [];
    }

    /** @return array<string,mixed> */
    private static function tenant(
        string $column,
        string $table,
        bool $nullable = false,
        CompanyBackupReferenceConstraint $constraint = CompanyBackupReferenceConstraint::Required,
    ): array {
        return [
            'columns' => [$column],
            'target' => 'table:' . $table,
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => $constraint->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => [],
        ];
    }

    /** @return array<string,mixed> */
    private static function actor(string $column, bool $nullable = false): array
    {
        return [
            'columns' => [$column],
            'target' => 'table:users',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::Actor->value,
            'constraint' => $nullable
                ? CompanyBackupReferenceConstraint::Optional->value
                : CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => $nullable ? ['null', 'restore_actor'] : ['restore_actor'],
        ];
    }
}
