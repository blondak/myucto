<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce historických karet drobného majetku. */
final class CompanyBackupSmallAssetsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'asset_kind',
            'purchase_invoice_id',
            'purchase_invoice_item_id',
            'cash_document_id',
            'document_ref',
            'name',
            'inventory_number',
            'vendor_client_id',
            'vendor_name',
            'acquisition_date',
            'put_into_use_date',
            'useful_months',
            'quantity',
            'unit_price',
            'price',
            'location',
            'responsible_person',
            'status',
            'disposed_at',
            'disposal_reason',
            'sale_invoice_id',
            'sold_at',
            'sale_price',
            'notes',
            'created_by',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * `document_ref` a `vendor_name` jsou uložené historické snapshoty, nikoliv ID.
     *
     * @return list<array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }>
     */
    public static function references(): array
    {
        return [
            self::tenant('cash_document_id', 'cash_documents', nullable: true),
            self::actor('created_by'),
            self::tenant('purchase_invoice_id', 'purchase_invoices', nullable: true),
            self::tenant('purchase_invoice_item_id', 'purchase_invoice_items', nullable: true),
            self::tenant('sale_invoice_id', 'invoices', nullable: true),
            self::tenant('supplier_id', 'supplier'),
            self::tenant('vendor_client_id', 'clients', nullable: true),
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
    ): array {
        return [
            'columns' => [$column],
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
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
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [$column],
            'fallbacks' => ['null', 'restore_actor'],
        ];
    }
}
