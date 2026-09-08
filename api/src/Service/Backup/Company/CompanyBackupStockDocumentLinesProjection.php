<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce tabulky stock_document_lines. */
final class CompanyBackupStockDocumentLinesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'document_id',
            'supplier_id',
            'stock_item_id',
            'doc_date',
            'qty',
            'unit_cost',
            'value_total',
            'extra_cost',
            'invoice_item_id',
            'purchase_invoice_item_id',
            'purchase_order_line_id',
            'source_description',
            'source_qty',
            'line_no',
            'note',
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
            [
                'columns' => ['document_id'],
                'target' => 'table:stock_documents',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['invoice_item_id'],
                'target' => 'table:invoice_items',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['invoice_item_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['purchase_invoice_item_id'],
                'target' => 'table:purchase_invoice_items',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['purchase_invoice_item_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['purchase_order_line_id'],
                'target' => 'table:purchase_order_lines',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['purchase_order_line_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['stock_item_id'],
                'target' => 'table:stock_items',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id'],
                'target' => 'table:supplier',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
        ];
    }
}
