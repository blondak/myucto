<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;

/** Jedna kanonická interní hrana mezi dvěma payload objekty. */
final readonly class CompanyBackupImportDependency
{
    public function __construct(
        public string $sourceRegistryKey,
        public string $targetRegistryKey,
        public CompanyBackupImportDependencyKind $kind,
        public string $signature,
        public bool $deferred,
    ) {
        if (!TenantDataDefinition::isValidKey($sourceRegistryKey)
            || !TenantDataDefinition::isValidKey($targetRegistryKey)
            || !str_starts_with($sourceRegistryKey, 'table:')
            || !str_starts_with($targetRegistryKey, 'table:')
            || preg_match('/^[\x20-\x7e]{1,4096}$/D', $signature) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Importní závislost nemá platný kanonický tvar.',
            );
        }
    }

    /**
     * @return array{
     *   source_registry_key:string,
     *   target_registry_key:string,
     *   kind:string,
     *   signature:string,
     *   deferred:bool
     * }
     */
    public function toArray(): array
    {
        return [
            'source_registry_key' => $this->sourceRegistryKey,
            'target_registry_key' => $this->targetRegistryKey,
            'kind' => $this->kind->value,
            'signature' => $this->signature,
            'deferred' => $this->deferred,
        ];
    }
}
