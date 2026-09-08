<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce tabulky stock_documents. */
final class CompanyBackupStockDocumentsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'doc_type',
            'origin',
            'warehouse_id',
            'warehouse_to_id',
            'doc_number',
            'doc_date',
            'description',
            'partner_name',
            'invoice_id',
            'purchase_invoice_id',
            'purchase_order_id',
            'stock_take_id',
            'journal_entry_id',
            'reversal_document_id',
            'status',
            'booked_at',
            'booked_by',
            'created_by',
            'created_at',
            'updated_at',
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
                'columns' => ['booked_by'],
                'target' => 'table:users',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::Actor->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['booked_by'],
                'fallbacks' => ['null', 'restore_actor'],
            ],
            [
                'columns' => ['created_by'],
                'target' => 'table:users',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::Actor->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['created_by'],
                'fallbacks' => ['null', 'restore_actor'],
            ],
            [
                'columns' => ['invoice_id'],
                'target' => 'table:invoices',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['invoice_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['journal_entry_id'],
                'target' => 'table:journal_entries',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['journal_entry_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['purchase_invoice_id'],
                'target' => 'table:purchase_invoices',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['purchase_invoice_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['purchase_order_id'],
                'target' => 'table:purchase_orders',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['purchase_order_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['reversal_document_id'],
                'target' => 'table:stock_documents',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['reversal_document_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['stock_take_id'],
                'target' => 'table:stock_takes',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['stock_take_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id'],
                'target' => 'table:supplier',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['warehouse_id'],
                'target' => 'table:warehouses',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['warehouse_to_id'],
                'target' => 'table:warehouses',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['warehouse_to_id'],
                'fallbacks' => [],
            ],
        ];
    }
}
