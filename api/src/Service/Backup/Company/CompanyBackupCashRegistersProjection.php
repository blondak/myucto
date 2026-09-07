<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce číselníku hotovostních pokladen. */
final class CompanyBackupCashRegistersProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'name',
            'currency_code',
            'account_code',
            'is_default',
            'own_series',
            'is_active',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * account_code může v daňové evidenci oprávněně existovat bez účtové osnovy
     * a currency_code nemá v currencies unikátní tenantový cílový klíč. Obě
     * hodnoty se proto zachovávají beze změny, nikoli jako falešné reference.
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
        return [[
            'columns' => ['supplier_id'],
            'target' => 'table:supplier',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ]];
    }
}
