<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce historických položek přijatých faktur. */
final class CompanyBackupPurchaseInvoiceItemsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'purchase_invoice_id',
            'description',
            'quantity',
            'unit',
            'unit_price_without_vat',
            'vat_rate_id',
            'vat_rate_snapshot',
            'total_without_vat',
            'total_vat',
            'total_with_vat',
            'order_index',
            'vat_classification_code',
            'stock_item_id',
            'purchase_order_line_id',
            'is_fixed_asset',
            'expense_kind',
            'expense_account_code',
            'accrual_from',
            'accrual_to',
            'expense_category_id',
        ];
    }

    /**
     * @return list<array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }>
     */
    public static function references(): array
    {
        return [
            // Legacy per-item category has no physical FK; deleted categories may remain in history.
            self::reference('expense_category_id', 'expense_categories', nullable: true,
                constraint: CompanyBackupReferenceConstraint::Optional),
            self::reference('purchase_invoice_id', 'purchase_invoices'),
            self::reference('purchase_order_line_id', 'purchase_order_lines', nullable: true),
            self::reference('stock_item_id', 'stock_items', nullable: true),
            self::reference('vat_rate_id', 'vat_rates',
                CompanyBackupReferenceMapping::GlobalNaturalKey),
        ];
    }

    /**
     * @return array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private static function reference(
        string $column,
        string $target,
        CompanyBackupReferenceMapping $mapping = CompanyBackupReferenceMapping::TenantId,
        bool $nullable = false,
        CompanyBackupReferenceConstraint $constraint = CompanyBackupReferenceConstraint::Required,
    ): array {
        return [
            'columns' => [$column],
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'mapping' => $mapping->value,
            'constraint' => $constraint->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => [],
        ];
    }
}
