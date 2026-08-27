<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

use MyInvoice\Service\Backup\CanonicalJson;

/** Verzionovaný SSOT klasifikace tenantových dat pro všechny exportní profily. */
final class TenantDataRegistry
{
    public const FORMAT = 'myucto-tenant-data-registry';
    public const COMPANY_BACKUP_PROFILE = 'company_backup';
    public const ACCOUNTING_ARCHIVE_PROFILE = 'accounting_archive';

    /** @var array<string,TenantDataDefinition> */
    private array $definitions = [];

    /** @var array<string,true> */
    private array $completeProfiles = [];

    /**
     * @param array<mixed> $definitions
     * @param array<mixed> $completeProfiles
     */
    public function __construct(
        public readonly int $version,
        array $definitions,
        array $completeProfiles = [],
    ) {
        if ($version < 1) {
            throw new \InvalidArgumentException('Verze tenantového registru musí být kladná.');
        }
        foreach ($definitions as $definition) {
            if (!$definition instanceof TenantDataDefinition) {
                throw new \InvalidArgumentException(
                    'Tenantový registr obsahuje neplatnou definici.',
                );
            }
            if (isset($this->definitions[$definition->key])) {
                throw new \InvalidArgumentException(
                    'Tenantový registr obsahuje duplicitní klíč.',
                );
            }
            $this->definitions[$definition->key] = $definition;
        }
        ksort($this->definitions, SORT_STRING);

        foreach ($completeProfiles as $profile) {
            if (!is_string($profile) || !TenantDataDefinition::isValidProfile($profile)) {
                throw new \InvalidArgumentException(
                    'Úplný profil tenantového registru nemá bezpečný identifikátor.',
                );
            }
            if (isset($this->completeProfiles[$profile])) {
                throw new \InvalidArgumentException(
                    'Tenantový registr obsahuje duplicitní úplný profil.',
                );
            }
            if ($this->definitionsFor($profile) === []) {
                throw new \InvalidArgumentException(
                    'Prázdný profil tenantového registru nelze označit jako úplný.',
                );
            }
            $this->completeProfiles[$profile] = true;
        }
    }

    public function definition(string $key): ?TenantDataDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /** @return list<TenantDataDefinition> */
    public function definitions(): array
    {
        return array_values($this->definitions);
    }

    /** @return list<TenantDataDefinition> */
    public function definitionsFor(string $profile): array
    {
        self::assertProfile($profile);
        return array_values(array_filter(
            $this->definitions,
            static fn (TenantDataDefinition $definition): bool => $definition->hasProfile($profile),
        ));
    }

    public function isComplete(string $profile): bool
    {
        self::assertProfile($profile);
        return isset($this->completeProfiles[$profile]);
    }

    public function fingerprintFor(string $profile): string
    {
        if (!$this->isComplete($profile)) {
            throw new IncompleteTenantDataRegistry(
                'Tenantový registr pro profil ' . $profile . ' není označen jako úplný.',
            );
        }
        return 'sha256:' . CanonicalJson::sha256([
            'format' => self::FORMAT,
            'version' => $this->version,
            'profile' => $profile,
            'definitions' => array_map(
                static fn (TenantDataDefinition $definition): array => $definition->toArray(),
                $this->definitionsFor($profile),
            ),
        ]);
    }

    private static function assertProfile(string $profile): void
    {
        if (!TenantDataDefinition::isValidProfile($profile)) {
            throw new \InvalidArgumentException(
                'Profil tenantového registru nemá bezpečný identifikátor.',
            );
        }
    }
}
