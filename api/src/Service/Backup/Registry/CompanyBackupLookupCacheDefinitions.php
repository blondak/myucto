<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

/** TTL cache externích lookupů, nikoli účetní evidence výsledků ověření. */
final class CompanyBackupLookupCacheDefinitions
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        $definitions = [];
        foreach (['ares_cache' => 'ic', 'crpdph_cache' => 'dic', 'vies_cache' => 'vat_id'] as $table => $key) {
            $definitions[] = new TenantDataDefinition(
                'table:' . $table, TenantDataObjectKind::Table, TenantDataPolicy::RuntimeDerived,
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
                ['primary_key' => [$key], 'feature_group' => 'core',
                    'reason' => 'expiring_external_lookup_cache_refetched_on_demand'],
            );
        }
        return $definitions;
    }
}
