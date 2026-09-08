<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce tabulky stock_takes. */
final class CompanyBackupStockTakesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'warehouse_id',
            'take_date',
            'status',
            'note',
            'counting_method',
            'responsible_count_name',
            'responsible_inventory_name',
            'started_at',
            'receipt_document_id',
            'issue_document_id',
            'created_by',
            'closed_by',
            'closed_at',
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
                'columns' => ['closed_by'],
                'target' => 'table:users',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::Actor->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['closed_by'],
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
                'columns' => ['issue_document_id'],
                'target' => 'table:stock_documents',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['issue_document_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['receipt_document_id'],
                'target' => 'table:stock_documents',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['receipt_document_id'],
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
        ];
    }
}
