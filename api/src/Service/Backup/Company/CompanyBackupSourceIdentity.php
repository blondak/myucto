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
