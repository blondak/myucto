<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

/**
 * Samoověřitelný snapshot jednoho úplného profilu. Cílový registr může být
 * novější; fingerprint proto dokládá zdrojové definice, není shodnostní brána.
 */
final readonly class TenantDataRegistrySnapshot
{
    private const MAX_DEFINITIONS = 10_000;

    private function __construct(
        public string $format,
        public string $profile,
        public string $fingerprint,
        public TenantDataRegistry $registry,
    ) {}

    public static function fromRegistry(TenantDataRegistry $registry, string $profile): self
    {
        $fingerprint = $registry->fingerprintFor($profile);
        $definitions = array_map(
            static fn (TenantDataDefinition $definition): TenantDataDefinition => new TenantDataDefinition(
                $definition->key,
                $definition->kind,
                $definition->policy,
                [$profile],
                $definition->detailsForProfile($profile),
            ),
            $registry->definitionsFor($profile),
        );
        self::assertRestorable($definitions);
        $scopedRegistry = new TenantDataRegistry(
            $registry->version,
            $definitions,
            [$profile],
        );
        if (!hash_equals($fingerprint, $scopedRegistry->fingerprintFor($profile))) {
            throw new \LogicException('Profilový snapshot změnil fingerprint registru.');
        }
        return new self(TenantDataRegistry::FORMAT, $profile, $fingerprint, $scopedRegistry);
    }

    public static function fromArray(mixed $snapshot): self
    {
        if (!is_array($snapshot) || array_is_list($snapshot)) {
            throw new \InvalidArgumentException(
                'Snapshot tenantového registru musí být JSON objekt.',
            );
        }
        $keys = array_keys($snapshot);
        sort($keys, SORT_STRING);
        if ($keys !== ['definitions', 'fingerprint', 'format', 'profile', 'version']) {
            throw new \InvalidArgumentException(
                'Snapshot tenantového registru má neznámá nebo chybějící pole.',
            );
        }
        $format = $snapshot['format'];
        $version = $snapshot['version'];
        $profile = $snapshot['profile'];
        $fingerprint = $snapshot['fingerprint'];
        $definitionValues = $snapshot['definitions'];
        if ($format !== TenantDataRegistry::FORMAT
            || !is_int($version)
            || $version < 1
            || !is_string($profile)
            || !TenantDataDefinition::isValidProfile($profile)
            || !is_string($fingerprint)
            || preg_match('/^sha256:[0-9a-f]{64}$/D', $fingerprint) !== 1
            || !is_array($definitionValues)
            || !array_is_list($definitionValues)
            || $definitionValues === []
            || count($definitionValues) > self::MAX_DEFINITIONS
        ) {
            throw new \InvalidArgumentException(
                'Snapshot tenantového registru má neplatnou obálku.',
            );
        }

        $definitions = [];
        $definitionKeys = [];
        foreach ($definitionValues as $definitionValue) {
            $definition = TenantDataDefinition::fromArray($definitionValue);
            if ($definition->profiles !== [$profile]) {
                throw new \InvalidArgumentException(
                    'Snapshot obsahuje definici mimo jediný deklarovaný profil.',
                );
            }
            if ($definition->details !== $definition->detailsForProfile($profile)) {
                throw new \InvalidArgumentException(
                    'Snapshot obsahuje metadata jiného profilu.',
                );
            }
            $definitions[] = $definition;
            $definitionKeys[] = $definition->key;
        }
        $sortedKeys = $definitionKeys;
        sort($sortedKeys, SORT_STRING);
        if ($definitionKeys !== $sortedKeys) {
            throw new \InvalidArgumentException(
                'Definice snapshotu nemají kanonické pořadí.',
            );
        }
        try {
            self::assertRestorable($definitions);
        } catch (IncompleteTenantDataRegistry $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }

        $registry = new TenantDataRegistry($version, $definitions, [$profile]);
        $actualFingerprint = $registry->fingerprintFor($profile);
        if (!hash_equals($actualFingerprint, $fingerprint)) {
            throw new \InvalidArgumentException(
                'Kontrolní fingerprint snapshotu neodpovídá vloženým definicím.',
            );
        }
        return new self(TenantDataRegistry::FORMAT, $profile, $fingerprint, $registry);
    }

    /**
     * @return array{
     *   format:string,
     *   version:int,
     *   profile:string,
     *   fingerprint:string,
     *   definitions:list<array{
     *     key:string,
     *     kind:string,
     *     policy:string,
     *     profiles:list<string>,
     *     details:array<string,mixed>
     *   }>
     * }
     */
    public function toArray(): array
    {
        return [
            'format' => $this->format,
            'version' => $this->registry->version,
            'profile' => $this->profile,
            'fingerprint' => $this->fingerprint,
            'definitions' => array_map(
                static fn (TenantDataDefinition $definition): array => $definition->toArray(),
                $this->registry->definitionsFor($this->profile),
            ),
        ];
    }

    /** @param list<TenantDataDefinition> $definitions */
    private static function assertRestorable(array $definitions): void
    {
        foreach ($definitions as $definition) {
            if ($definition->policy === TenantDataPolicy::Unsupported) {
                throw new IncompleteTenantDataRegistry(
                    'Snapshot obsahuje nepodporovaný objekt ' . $definition->key . '.',
                );
            }
        }
    }
}
