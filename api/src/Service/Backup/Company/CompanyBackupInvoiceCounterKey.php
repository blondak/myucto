<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Nula označuje obecnou řadu, nikdy existujícího klienta či kategorii. */
final class CompanyBackupInvoiceCounterKey
{
    public const REGISTRY_KEY = 'table:invoice_counters';
    public const COLUMNS = ['supplier_id', 'client_id', 'revenue_category_id', 'invoice_type', 'period'];

    /** @param array<mixed> $values */
    public static function permitsZero(string $registryKey, array $values, string $column): bool
    {
        return $registryKey === self::REGISTRY_KEY
            && array_keys($values) === self::COLUMNS
            && in_array($column, ['client_id', 'revenue_category_id'], true)
            && is_int($values['supplier_id']) && $values['supplier_id'] > 0
            && is_int($values['client_id']) && $values['client_id'] >= 0
            && is_int($values['revenue_category_id']) && $values['revenue_category_id'] >= 0
            && in_array($values['invoice_type'], ['invoice', 'proforma', 'credit_note'], true)
            && is_string($values['period']) && $values['period'] !== '';
    }
}
