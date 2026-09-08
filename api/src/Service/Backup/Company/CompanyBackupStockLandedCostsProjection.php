<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce tabulky stock_landed_costs. */
final class CompanyBackupStockLandedCostsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'document_id',
            'purchase_invoice_id',
            'purchase_invoice_item_id',
            'description',
            'amount',
            'allocation',
            'created_at',
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
                'columns' => ['purchase_invoice_id'],
                'target' => 'table:purchase_invoices',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['purchase_invoice_id'],
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
                'columns' => ['supplier_id'],
                'target' => 'table:supplier',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
        ];
    }
}
