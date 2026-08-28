<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce úhrad dokladů zápočtem proti účtu. */
final class CompanyBackupInvoiceSettlementsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'doc_type',
            'doc_id',
            'settled_on',
            'amount',
            'account_id',
            'note',
            'status',
            'journal_entry_id',
            'reversal_entry_id',
            'invoice_payment_id',
            'created_by',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return list<array{
     *   columns:list<string>,
     *   target:string,
     *   target_columns:list<string>,
     *   mapping:string,
     *   constraint:string,
     *   nullable_columns:list<string>,
     *   fallbacks:list<string>,
     *   condition?:array{column:string,equals:string}
     * }>
     */
    public static function references(): array
    {
        return [
            self::tenant('account_id', 'chart_of_accounts'),
            self::actor('created_by'),
            self::document('invoice', 'invoices'),
            self::document('purchase_invoice', 'purchase_invoices'),
            self::tenant(
                'invoice_payment_id',
                'invoice_payments',
                nullable: true,
                constraint: CompanyBackupReferenceConstraint::Optional,
            ),
            self::tenant('journal_entry_id', 'journal_entries', nullable: true),
            self::tenant('reversal_entry_id', 'journal_entries', nullable: true),
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

    /**
     * @return array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,
     *   fallbacks:list<string>,condition:array{column:string,equals:string}
     * }
     */
    private static function document(string $type, string $target): array
    {
        return [
            'columns' => ['doc_id'],
            'condition' => [
                'column' => 'doc_type',
                'equals' => $type,
            ],
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Optional->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ];
    }
}
