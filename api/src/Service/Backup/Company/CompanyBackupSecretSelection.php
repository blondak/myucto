<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/** Registry-bound opt-in seznam konkrétních credential sloupců a řádků. */
final readonly class CompanyBackupSecretSelection
{
    private const MAX_ENTRIES = 10_000;

    /** @var list<CompanyBackupSecretSelectionEntry> */
    private array $entries;

    /** @param list<CompanyBackupSecretSelectionEntry> $entries */
    private function __construct(
        public string $registryFingerprint,
        array $entries,
    ) {
        $this->entries = $entries;
    }

    public static function none(
        TenantDataRegistrySnapshot $registry,
    ): self {
        return new self($registry->fingerprint, []);
    }

    public static function fromArray(
        mixed $value,
        TenantDataRegistrySnapshot $registry,
    ): self {
        if (!is_array($value) || array_is_list($value)) {
            throw self::error('secret_selection_invalid');
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $fingerprint = $value['registry_fingerprint'] ?? null;
        $rawEntries = $value['entries'] ?? null;
        if ($keys !== ['entries', 'registry_fingerprint']
            || !is_string($fingerprint)
            || preg_match('/^sha256:[0-9a-f]{64}$/D', $fingerprint) !== 1
            || !is_array($rawEntries)
            || !array_is_list($rawEntries)
            || count($rawEntries) > self::MAX_ENTRIES
        ) {
            throw self::error('secret_selection_invalid');
        }
        if (!hash_equals($registry->fingerprint, $fingerprint)) {
            throw self::error('secret_selection_registry_mismatch');
        }

        $entries = [];
        $seen = [];
        foreach ($rawEntries as $rawEntry) {
            [$definition, $scope, $name, $policy] = self::resolveDeclaration(
                $rawEntry,
                $registry,
            );
            if ($policy === TenantSecretPolicy::PersonalWithDualConsent) {
                throw self::error('secret_selection_consent_required');
            }
            if ($policy !== TenantSecretPolicy::OptionalCredential) {
                throw self::error('secret_selection_policy_forbidden');
            }
            if ($scope !== CompanyBackupSecretScope::Column
                || !$definition->policy->hasMachineDataPayload()
            ) {
                throw self::error('secret_selection_scope_unsupported');
            }
            $primaryKey = self::primaryKey($definition);
            $entry = CompanyBackupSecretSelectionEntry::fromArray(
                $rawEntry,
                $policy,
                $primaryKey,
            );
            if ($entry->name !== $name || $entry->scope !== $scope) {
                throw self::error('secret_selection_scope_mismatch');
            }
            $signature = $entry->valueSignature();
            if (isset($seen[$signature])) {
                throw self::error('secret_selection_duplicate');
            }
            $seen[$signature] = true;
            $entries[] = $entry;
        }
        usort(
            $entries,
            static fn (
                CompanyBackupSecretSelectionEntry $left,
                CompanyBackupSecretSelectionEntry $right,
            ): int => strcmp($left->valueSignature(), $right->valueSignature()),
        );
        return new self($registry->fingerprint, $entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /** @return list<CompanyBackupSecretSelectionEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<CompanyBackupSecretSelectionEntry> */
    public function entriesFor(string $registryKey): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (CompanyBackupSecretSelectionEntry $entry): bool =>
                $entry->registryKey === $registryKey,
        ));
    }

    /** @return array<string,int> */
    public function countsByDeclaration(): array
    {
        $counts = [];
        foreach ($this->entries as $entry) {
            $signature = $entry->declarationSignature();
            $counts[$signature] = ($counts[$signature] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);
        return $counts;
    }

    /** @return array{registry_fingerprint:string,entries:list<array<string,mixed>>} */
    public function toArray(): array
    {
        return [
            'registry_fingerprint' => $this->registryFingerprint,
            'entries' => array_map(
                static fn (CompanyBackupSecretSelectionEntry $entry): array =>
                    $entry->toArray(),
                $this->entries,
            ),
        ];
    }

    /**
     * @return array{TenantDataDefinition,CompanyBackupSecretScope,string,TenantSecretPolicy}
     */
    private static function resolveDeclaration(
        mixed $value,
        TenantDataRegistrySnapshot $registry,
    ): array {
        if (!is_array($value) || array_is_list($value)) {
            throw self::error('secret_selection_invalid');
        }
        $registryKey = $value['registry_key'] ?? null;
        $scopeValue = $value['scope'] ?? null;
        $name = $value['name'] ?? null;
        $scope = is_string($scopeValue)
            ? CompanyBackupSecretScope::tryFrom($scopeValue)
            : null;
        if (!is_string($registryKey)
            || !TenantDataDefinition::isValidKey($registryKey)
            || $scope === null
            || !is_string($name)
        ) {
            throw self::error('secret_selection_invalid');
        }
        $definition = $registry->registry->definition($registryKey);
        if ($definition === null || !$definition->hasProfile($registry->profile)) {
            throw self::error('secret_selection_scope_mismatch');
        }

        if ($scope === CompanyBackupSecretScope::Column) {
            $policies = CompanyBackupSecretColumnSet::fromArray(
                $definition->details['secrets'] ?? null,
                $definition->key,
            )->policies;
            $policy = $policies[$name] ?? null;
        } else {
            if ($definition->policy !== TenantDataPolicy::OptionalCredential) {
                throw self::error('secret_selection_scope_mismatch');
            }
            try {
                $projection = CompanyBackupCredentialTableProjection::fromDefinition(
                    $definition,
                );
            } catch (CompanyBackupDataSourceException $e) {
                throw self::error('secret_selection_scope_mismatch', $e);
            }
            $policy = null;
            foreach ($projection->variants as $variant) {
                if ($variant['name'] === $name) {
                    $policy = $variant['policy'];
                    break;
                }
            }
        }
        if (!$policy instanceof TenantSecretPolicy) {
            throw self::error('secret_selection_scope_mismatch');
        }
        return [$definition, $scope, $name, $policy];
    }

    /** @return list<string> */
    private static function primaryKey(TenantDataDefinition $definition): array
    {
        $value = $definition->details['primary_key'] ?? null;
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw self::error('secret_selection_primary_key_invalid');
        }
        $result = [];
        foreach ($value as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || in_array($column, $result, true)
            ) {
                throw self::error('secret_selection_primary_key_invalid');
            }
            $result[] = $column;
        }
        return $result;
    }

    private static function error(
        string $code,
        ?\Throwable $previous = null,
    ): CompanyBackupSecretSelectionException {
        return new CompanyBackupSecretSelectionException($code, $previous);
    }
}
