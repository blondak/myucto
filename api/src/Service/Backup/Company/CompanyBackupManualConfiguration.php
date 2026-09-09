<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\CompanyBackupSupplierDomainsDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Tenant\HostnameNormalizer;

/** Archivovaná konfigurace bez SQL identity a bez přenosu oprávnění k doméně. */
final readonly class CompanyBackupManualConfiguration
{
    public const MAX_DOMAINS = 1000;

    /** @param list<array{hostname:string,purpose:string,is_primary_portal:bool,is_primary_public:bool}> $domains */
    private function __construct(
        public array $domains,
        public int $sourceKeyCount,
        public string $technicalValidationBindingSha256,
    ) {}

    public function rowCount(): int
    {
        return count($this->domains);
    }

    public static function assertDefinition(TenantDataDefinition $definition): void
    {
        // Nová ruční konfigurace vyžaduje vlastní kontrolovaný report, nikoli
        // obecnou výjimku umožňující přeskočit libovolnou tenantovou tabulku.
        if (CanonicalJson::encode($definition->toArray()) !== CanonicalJson::encode(
            CompanyBackupSupplierDomainsDefinition::definition()->toArray(),
        )) {
            throw new CompanyBackupDataSourceException('data_manual_configuration_contract_invalid', $definition->key);
        }
    }

    /**
     * Vynechaní aktéři původního ověření nejsou reference importované firmy.
     * Výjimka platí jen pro tyto dvě přesné FK v pevně ověřené definici.
     */
    public static function referenceSchema(
        CompanyBackupTableProjection $projection,
        CompanyBackupTableReferenceSchema $schema,
    ): CompanyBackupTableReferenceSchema {
        if ($projection->policy !== TenantDataPolicy::ManualConfiguration) {
            return $schema;
        }
        return new CompanyBackupTableReferenceSchema($schema->nullableColumns,
            array_values(array_filter($schema->foreignKeys,
                static fn (CompanyBackupForeignKey $key): bool => !in_array($key->signature(),
                    ['created_by->users:id', 'updated_by->users:id'], true))));
    }

    /**
     * @param array<string,mixed> $row
     * @return array{hostname:string,purpose:string,is_primary_portal:bool,is_primary_public:bool}
     */
    public static function domain(array $row): array
    {
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        $host = $row['hostname'] ?? null;
        $purpose = $row['purpose'] ?? null;
        $portal = $row['is_primary_portal'] ?? null;
        $public = $row['is_primary_public'] ?? null;
        try {
            if ($keys !== ['hostname', 'id', 'is_primary_portal', 'is_primary_public', 'purpose', 'supplier_id']
                || !is_string($host) || (new HostnameNormalizer())->normalizeDomain($host) !== $host
                || !in_array($purpose, ['portal', 'public_links', 'all'], true)
                || !in_array($portal, [0, 1, '0', '1'], true)
                || !in_array($public, [0, 1, '0', '1'], true)
                || ($portal == 1 && $purpose === 'public_links')
                || ($public == 1 && $purpose === 'portal')
            ) {
                throw new \InvalidArgumentException('Neplatná konfigurace domény.');
            }
        } catch (\InvalidArgumentException $e) {
            throw new CompanyBackupDataSourceException('data_manual_domain_invalid',
                'table:supplier_domains', previous: $e);
        }
        return ['hostname' => $host, 'purpose' => $purpose,
            'is_primary_portal' => $portal == 1, 'is_primary_public' => $public == 1];
    }

    public static function collect(
        CompanyBackupImportSource $source,
        CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ): self {
        $domains = [];
        $sourceKeys = 0;
        foreach ($source->dataInventory()->objects as $object) {
            $definition = $source->targetRegistry()->registry->definition($object->registryKey);
            if ($definition?->policy !== TenantDataPolicy::ManualConfiguration) {
                continue;
            }
            self::assertDefinition($definition);
            if ($object->rows > min(self::MAX_DOMAINS, $limits->maxSourceIdentities)) {
                throw new CompanyBackupImportWriteException('import_manual_configuration_limit', $object->registryKey);
            }
            $projection = CompanyBackupSourceIdentityProjection::fromDefinition($definition, $limits);
            $source->consumeRows($object->registryKey, static function (array $row) use (
                &$domains, &$sourceKeys, $projection, $object,
            ): void {
                $domain = self::domain($row);
                if (count($domains) >= $object->rows || isset($domains[$domain['hostname']])) {
                    throw new CompanyBackupImportWriteException('import_manual_configuration_invalid', $object->registryKey);
                }
                $domains[$domain['hostname']] = $domain;
                $sourceKeys += count($projection->identityForRow($row)->keys());
            });
            if (count($domains) !== $object->rows) {
                throw new CompanyBackupImportWriteException('import_manual_configuration_count_mismatch', $object->registryKey);
            }
        }
        ksort($domains, SORT_STRING);
        return new self(array_values($domains), $sourceKeys, $source->technicalValidationBindingSha256());
    }

    public function bindingSha256(): string
    {
        return CanonicalJson::sha256(['report' => $this->toArray(),
            'source_key_count' => $this->sourceKeyCount,
            'technical_validation_binding_sha256' => $this->technicalValidationBindingSha256]);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'domains' => $this->domains,
            'required_actions' => $this->domains === [] ? [] : [
                'manually_reassign_domain_without_changing_source_during_restore',
                'verify_domain_ownership_again',
                'review_purpose_and_primary_domain_before_activation',
            ],
        ];
    }
}
