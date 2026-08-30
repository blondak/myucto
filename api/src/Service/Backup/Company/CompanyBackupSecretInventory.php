<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/** Úplný manifestový inventář bezpečně vynechaných secret hodnot. */
final readonly class CompanyBackupSecretInventory
{
    public const FORMAT = 'myucto-company-secret-inventory';
    public const VERSION = 1;
    private const MAX_OMISSIONS = 10_000;

    /** @var list<CompanyBackupSecretOmission> */
    public array $omissions;

    /** @param list<CompanyBackupSecretOmission> $omissions */
    private function __construct(
        array $omissions,
        public string $registryFingerprint,
        public ?CompanyBackupSecretEnvelopeDescriptor $envelope,
        private bool $envelopeRequired,
        private bool $envelopeAllowed,
    ) {
        $this->omissions = $omissions;
    }

    public static function fromArray(
        mixed $inventory,
        TenantDataRegistrySnapshot $registry,
    ): self {
        if (!is_array($inventory) || array_is_list($inventory)) {
            throw new \InvalidArgumentException('Inventář secrets musí být JSON objekt.');
        }
        $keys = array_keys($inventory);
        sort($keys, SORT_STRING);
        $baseKeys = ['format', 'omissions', 'version'];
        $envelopeKeys = ['envelope', 'format', 'omissions', 'version'];
        if ($keys !== $baseKeys && $keys !== $envelopeKeys
            || $inventory['format'] !== self::FORMAT
            || $inventory['version'] !== self::VERSION
            || !is_array($inventory['omissions'])
            || !array_is_list($inventory['omissions'])
            || count($inventory['omissions']) > self::MAX_OMISSIONS
        ) {
            throw new \InvalidArgumentException('Inventář secrets má neplatnou obálku.');
        }

        $required = self::requiredDeclarations($registry);
        if (count($inventory['omissions']) !== count($required)) {
            throw new \InvalidArgumentException(
                'Inventář secrets nemá úplný registry rozsah.',
            );
        }
        $omissions = [];
        foreach ($required as $index => $declaration) {
            $omissions[] = CompanyBackupSecretOmission::fromArray(
                $inventory['omissions'][$index],
                $declaration,
            );
        }
        [$envelopeRequired, $envelopeAllowed] = self::envelopePolicy($registry);
        try {
            $envelope = array_key_exists('envelope', $inventory)
                ? CompanyBackupSecretEnvelopeDescriptor::fromArray(
                    $inventory['envelope'],
                )
                : null;
        } catch (CompanyBackupSecretEnvelopeException $e) {
            throw new \InvalidArgumentException(
                'Inventář secrets má neplatný envelope descriptor.',
                0,
                $e,
            );
        }
        if ($envelope !== null && !$envelopeAllowed) {
            throw new \InvalidArgumentException(
                'Inventář secrets deklaruje envelope bez přenositelného secretu.',
            );
        }
        return new self(
            $omissions,
            $registry->fingerprint,
            $envelope,
            $envelopeRequired,
            $envelopeAllowed,
        );
    }

    /**
     * @param array<string,int> $counts deklarace signature => počet hodnot
     */
    public static function fromCounts(
        array $counts,
        TenantDataRegistrySnapshot $registry,
    ): self {
        $required = self::requiredDeclarations($registry);
        $expected = array_map(
            static fn (CompanyBackupSecretDeclaration $declaration): string =>
                $declaration->signature(),
            $required,
        );
        $actual = array_keys($counts);
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException(
                'Počty vynechaných secrets nemají úplný registry rozsah.',
            );
        }

        $values = [];
        foreach ($required as $declaration) {
            $count = $counts[$declaration->signature()] ?? null;
            if (!is_int($count) || $count < 0) {
                throw new \InvalidArgumentException(
                    'Počet vynechaných secrets není platný.',
                );
            }
            $values[] = [
                'registry_key' => $declaration->registryKey,
                'scope' => $declaration->scope->value,
                'name' => $declaration->name,
                'policy' => $declaration->policy->value,
                'reason' => $declaration->reason->value,
                'count' => $count,
            ];
        }
        return self::fromArray([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'omissions' => $values,
        ], $registry);
    }

    /** @return list<CompanyBackupSecretDeclaration> */
    public static function requiredDeclarations(
        TenantDataRegistrySnapshot $registry,
    ): array {
        $declarations = [];
        try {
            foreach ($registry->registry->definitionsFor($registry->profile) as $definition) {
                $columns = self::columnDeclarations($definition);
                if ($definition->policy === TenantDataPolicy::PersonalSecretAttachment
                    && $columns === []
                ) {
                    throw new \InvalidArgumentException(
                        'Osobní secret příloha nemá inventarizované hodnoty.',
                    );
                }
                foreach ($columns as $declaration) {
                    self::addDeclaration($declarations, $declaration);
                }
                if ($definition->policy === TenantDataPolicy::OptionalCredential
                    || array_key_exists(
                        'company_backup_credential',
                        $definition->details,
                    )
                ) {
                    $credential = CompanyBackupCredentialTableProjection::fromDefinition(
                        $definition,
                    );
                    foreach ($credential->variants as $variant) {
                        if (CompanyBackupSecretOmissionReason::forPolicy(
                            $variant['policy'],
                        ) === null) {
                            continue;
                        }
                        $declaration = new CompanyBackupSecretDeclaration(
                            $definition->key,
                            CompanyBackupSecretScope::CredentialVariant,
                            $variant['name'],
                            $variant['policy'],
                        );
                        self::addDeclaration($declarations, $declaration);
                    }
                }
            }
        } catch (CompanyBackupDataSourceException $e) {
            throw new \InvalidArgumentException(
                'Registry secret deklarace není platná.',
                0,
                $e,
            );
        }
        ksort($declarations, SORT_STRING);
        return array_values($declarations);
    }

    /**
     * @return array{
     *   format:string,
     *   version:int,
     *   omissions:list<array<string,mixed>>,
     *   envelope?:array<string,mixed>
     * }
     */
    public function toArray(): array
    {
        $result = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'omissions' => array_map(
                static fn (CompanyBackupSecretOmission $omission): array =>
                    $omission->toArray(),
                $this->omissions,
            ),
        ];
        if ($this->envelope !== null) {
            $result['envelope'] = $this->envelope->toArray();
        }
        return $result;
    }

    public function withEnvelope(
        CompanyBackupSecretEnvelopeDescriptor $envelope,
    ): self {
        if (!$this->envelopeAllowed) {
            throw new \InvalidArgumentException(
                'Registry nemá secret, který smí vstoupit do envelope.',
            );
        }
        return new self(
            $this->omissions,
            $this->registryFingerprint,
            $envelope,
            $this->envelopeRequired,
            $this->envelopeAllowed,
        );
    }

    /**
     * @param array<string,string> $entryHashes
     * @param array<string,int> $entryBytes
     */
    public function assertArchiveEntries(
        array $entryHashes,
        array $entryBytes = [],
    ): void
    {
        $secretPaths = array_values(array_filter(
            array_keys($entryHashes),
            static fn (string $path): bool => str_starts_with($path, 'secrets/'),
        ));
        sort($secretPaths, SORT_STRING);
        if ($this->envelope === null) {
            if ($this->envelopeRequired) {
                throw new CompanyBackupArchiveException(
                    'secret_envelope_required',
                );
            }
            if ($secretPaths !== []) {
                throw new CompanyBackupArchiveException(
                    'secret_inventory_scope_mismatch',
                    $secretPaths[0],
                );
            }
            return;
        }

        $path = $this->envelope->path;
        if (!isset($entryHashes[$path])) {
            throw new CompanyBackupArchiveException(
                'secret_envelope_entry_missing',
                $path,
            );
        }
        if ($secretPaths !== [$path]) {
            $unexpected = array_values(array_diff($secretPaths, [$path]));
            throw new CompanyBackupArchiveException(
                'secret_inventory_scope_mismatch',
                $unexpected[0] ?? $path,
            );
        }
        if (!hash_equals($this->envelope->sha256, $entryHashes[$path])) {
            throw new CompanyBackupArchiveException(
                'secret_envelope_checksum_mismatch',
                $path,
            );
        }
        if ($entryBytes !== []
            && ($entryBytes[$path] ?? null) !== $this->envelope->bytes
        ) {
            throw new CompanyBackupArchiveException(
                'secret_envelope_size_mismatch',
                $path,
            );
        }
    }

    /** @return array{bool,bool} required, allowed */
    private static function envelopePolicy(
        TenantDataRegistrySnapshot $registry,
    ): array {
        $required = false;
        $allowed = false;
        foreach ($registry->registry->definitionsFor($registry->profile) as $definition) {
            if ($definition->policy === TenantDataPolicy::ProtectedDomainSecret) {
                $required = true;
                $allowed = true;
            }
            if (array_key_exists('secrets', $definition->details)) {
                $policies = CompanyBackupSecretColumnSet::fromArray(
                    $definition->details['secrets'],
                    $definition->key,
                )->policies;
                foreach ($policies as $policy) {
                    if ($policy === TenantSecretPolicy::ProtectedDomainSecret) {
                        $required = true;
                        $allowed = true;
                    } elseif (in_array($policy, [
                        TenantSecretPolicy::OptionalCredential,
                        TenantSecretPolicy::PersonalWithDualConsent,
                    ], true)) {
                        $allowed = true;
                    }
                }
            }
            if ($definition->policy === TenantDataPolicy::OptionalCredential
                || array_key_exists('company_backup_credential', $definition->details)
            ) {
                $credential = CompanyBackupCredentialTableProjection::fromDefinition(
                    $definition,
                );
                foreach ($credential->variants as $variant) {
                    if (in_array($variant['policy'], [
                        TenantSecretPolicy::OptionalCredential,
                        TenantSecretPolicy::PersonalWithDualConsent,
                    ], true)) {
                        $allowed = true;
                    }
                }
            }
        }
        return [$required, $allowed];
    }

    /** @return list<CompanyBackupSecretDeclaration> */
    private static function columnDeclarations(
        TenantDataDefinition $definition,
    ): array {
        if (!array_key_exists('secrets', $definition->details)) {
            return [];
        }
        $policies = CompanyBackupSecretColumnSet::fromArray(
            $definition->details['secrets'],
            $definition->key,
        )->policies;
        $declarations = [];
        foreach ($policies as $column => $policy) {
            if (CompanyBackupSecretOmissionReason::forPolicy($policy) === null) {
                continue;
            }
            $declarations[] = new CompanyBackupSecretDeclaration(
                $definition->key,
                CompanyBackupSecretScope::Column,
                $column,
                $policy,
            );
        }
        return $declarations;
    }

    /**
     * @param array<string,CompanyBackupSecretDeclaration> $declarations
     */
    private static function addDeclaration(
        array &$declarations,
        CompanyBackupSecretDeclaration $declaration,
    ): void {
        $signature = $declaration->signature();
        if (isset($declarations[$signature])) {
            throw new \InvalidArgumentException(
                'Registry obsahuje duplicitní secret deklaraci.',
            );
        }
        $declarations[$signature] = $declaration;
    }
}
