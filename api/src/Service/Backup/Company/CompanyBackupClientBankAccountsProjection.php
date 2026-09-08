<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce bankovních účtů obchodních partnerů. */
final class CompanyBackupClientBankAccountsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'client_id',
            'account_number',
            'bank_code',
            'iban',
            'account_key',
            'bank_key',
            'source_manual',
            'source_vat_registry',
            'source_bank_statement',
            'last_bank_transaction_id',
            'is_active',
            'first_seen_at',
            'last_seen_at',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * Poslední bankovní transakce musí patřit do výpisu vlastněného firmou;
     * tato nullable vazba nesmí rozšířit výběr o cizí či nevlastněný výpis.
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
            self::tenant('client_id', 'clients'),
            self::tenant(
                'last_bank_transaction_id',
                'bank_transactions',
                nullable: true,
            ),
            self::tenant('supplier_id', 'supplier'),
        ];
    }

    /**
     * @return array{
     *   columns:list<string>,
     *   target:string,
     *   target_columns:list<string>,
     *   mapping:string,
     *   constraint:string,
     *   nullable_columns:list<string>,
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
}
