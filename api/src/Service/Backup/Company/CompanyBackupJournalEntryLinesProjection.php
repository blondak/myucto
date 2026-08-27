<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce řádků účetního deníku včetně analytických dimenzí. */
final class CompanyBackupJournalEntryLinesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'entry_id',
            'supplier_id',
            'account_id',
            'side',
            'amount',
            'currency_code',
            'fx_rate',
            'amount_foreign',
            'cost_center',
            'project_id',
            'line_no',
        ];
    }

    /**
     * `currency_code` je historická ISO hodnota, ne reference na konkrétní řádek
     * `currencies`; firma může mít pro stejný kód více bankovních účtů.
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
            self::tenant('project_id', 'projects', nullable: true),
            [
                'columns' => ['supplier_id', 'account_id'],
                'target' => 'table:chart_of_accounts',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id', 'cost_center'],
                'target' => 'table:cost_centers',
                'target_columns' => ['supplier_id', 'code'],
                'mapping' => CompanyBackupReferenceMapping::TenantNaturalKey->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['cost_center'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id', 'entry_id'],
                'target' => 'table:journal_entries',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
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
