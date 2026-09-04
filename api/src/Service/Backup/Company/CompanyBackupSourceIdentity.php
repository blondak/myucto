<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataPolicy;

/** Všechny dovolené zdrojové klíče jednoho obnovitelného řádku. */
final readonly class CompanyBackupSourceIdentity
{
    /** @var list<CompanyBackupSourceKey> */
    public array $referenceKeys;

    /** @var list<CompanyBackupSourceKey> */
    private array $keys;

    /**
     * @param list<CompanyBackupSourceKey> $referenceKeys
     */
    public function __construct(
        public TenantDataPolicy $policy,
        public CompanyBackupSourceKey $primaryKey,
        public ?CompanyBackupSourceKey $tenantScopedPrimaryKey,
        public ?CompanyBackupSourceKey $naturalKey,
        array $referenceKeys,
    ) {
        if (!$policy->hasMachineDataPayload()
            || ($policy === TenantDataPolicy::GlobalReference
                && ($tenantScopedPrimaryKey !== null || $naturalKey === null))
        ) {
            throw new \InvalidArgumentException(
                'Politika zdrojové identity neodpovídá jejím klíčům.',
            );
        }
        if ($tenantScopedPrimaryKey !== null
            && $tenantScopedPrimaryKey->columns !== [
                'supplier_id',
                ...$primaryKey->columns,
            ]
        ) {
            throw new \InvalidArgumentException(
                'Tenantový primární klíč nemá kanonické souřadnice.',
            );
        }
        $keys = [];
        foreach ([
            $primaryKey,
            $tenantScopedPrimaryKey,
            $naturalKey,
            ...$referenceKeys,
        ] as $key) {
            if ($key === null) {
                continue;
            }
            if ($key->registryKey !== $primaryKey->registryKey) {
                throw new \InvalidArgumentException(
                    'Klíče zdrojové identity musí patřit stejnému objektu.',
                );
            }
            $existing = $keys[$key->id] ?? null;
            if ($existing !== null && !$existing->equals($key)) {
                throw new \LogicException('Kolize kanonických zdrojových klíčů.');
            }
            $keys[$key->id] = $key;
        }
        $this->referenceKeys = $referenceKeys;
        $this->keys = array_values($keys);
    }

    public static function fromArray(
        mixed $value,
        int $maxKeyBytes = CompanyBackupSourceKey::DEFAULT_MAX_BYTES,
    ): self {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('Uložená zdrojová identita není objekt.');
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'natural_key',
            'policy',
            'primary_key',
            'reference_keys',
            'tenant_scoped_primary_key',
        ]) {
            throw new \InvalidArgumentException(
                'Uložená zdrojová identita nemá přesná pole.',
            );
        }
        $policyValue = $value['policy'];
        $policy = is_string($policyValue)
            ? TenantDataPolicy::tryFrom($policyValue)
            : null;
        $primaryValue = $value['primary_key'];
        $tenantValue = $value['tenant_scoped_primary_key'];
        $naturalValue = $value['natural_key'];
        $referenceValues = $value['reference_keys'];
        if ($policy === null
            || !is_array($primaryValue)
            || ($tenantValue !== null && !is_array($tenantValue))
            || ($naturalValue !== null && !is_array($naturalValue))
            || !is_array($referenceValues)
            || !array_is_list($referenceValues)
        ) {
            throw new \InvalidArgumentException(
                'Uložená zdrojová identita má neplatné typy.',
            );
        }
        $referenceKeys = [];
        foreach ($referenceValues as $referenceValue) {
            $referenceKeys[] = CompanyBackupSourceKey::fromArray(
                $referenceValue,
                $maxKeyBytes,
            );
        }
        return new self(
            $policy,
            CompanyBackupSourceKey::fromArray($primaryValue, $maxKeyBytes),
            $tenantValue === null
                ? null
                : CompanyBackupSourceKey::fromArray($tenantValue, $maxKeyBytes),
            $naturalValue === null
                ? null
                : CompanyBackupSourceKey::fromArray($naturalValue, $maxKeyBytes),
            $referenceKeys,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'policy' => $this->policy->value,
            'primary_key' => $this->primaryKey->toArray(),
            'tenant_scoped_primary_key' =>
                $this->tenantScopedPrimaryKey?->toArray(),
            'natural_key' => $this->naturalKey?->toArray(),
            'reference_keys' => array_map(
                static fn (CompanyBackupSourceKey $key): array => $key->toArray(),
                $this->referenceKeys,
            ),
        ];
    }

    /** @return list<CompanyBackupSourceKey> */
    public function keys(): array
    {
        return $this->keys;
    }

    public function hasKey(CompanyBackupSourceKey $key): bool
    {
        foreach ($this->keys as $candidate) {
            if ($candidate->id === $key->id && $candidate->equals($key)) {
                return true;
            }
        }
        return false;
    }

    public function hasPrimaryKey(CompanyBackupSourceKey $key): bool
    {
        return $this->primaryKey->equals($key);
    }

    public function hasTenantIdKey(CompanyBackupSourceKey $key): bool
    {
        return $this->hasPrimaryKey($key)
            || $this->tenantScopedPrimaryKey?->equals($key) === true;
    }

    public function hasNaturalKey(CompanyBackupSourceKey $key): bool
    {
        return $this->naturalKey?->equals($key) === true;
    }

    public function hasReferenceKey(CompanyBackupSourceKey $key): bool
    {
        foreach ($this->referenceKeys as $referenceKey) {
            if ($referenceKey->equals($key)) {
                return true;
            }
        }
        return false;
    }
}
