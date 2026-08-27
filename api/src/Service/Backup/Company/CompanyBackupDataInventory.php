<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;

/** Úplný manifestový inventář registrovaných JSONL dat strojového snapshotu. */
final readonly class CompanyBackupDataInventory
{
    public const FORMAT = 'myucto-company-data-inventory';
    public const VERSION = 1;
    private const MAX_OBJECTS = 10_000;

    /** @var list<CompanyBackupDataObject> */
    public array $objects;

    /** @var array<string,CompanyBackupDataObject> */
    private array $objectsByRegistryKey;

    /**
     * @param list<CompanyBackupDataObject> $objects
     * @param array<string,CompanyBackupDataObject> $objectsByRegistryKey
     */
    private function __construct(
        array $objects,
        array $objectsByRegistryKey,
        public string $registryFingerprint,
    ) {
        $this->objects = $objects;
        $this->objectsByRegistryKey = $objectsByRegistryKey;
    }

    public static function fromArray(mixed $inventory, TenantDataRegistrySnapshot $registry): self
    {
        if (!is_array($inventory) || array_is_list($inventory)) {
            throw new \InvalidArgumentException(
                'Inventář strojových dat musí být JSON objekt.',
            );
        }
        $keys = array_keys($inventory);
        sort($keys, SORT_STRING);
        if ($keys !== ['format', 'objects', 'version']) {
            throw new \InvalidArgumentException(
                'Inventář strojových dat má neznámá nebo chybějící pole.',
            );
        }
        $values = $inventory['objects'];
        if ($inventory['format'] !== self::FORMAT
            || $inventory['version'] !== self::VERSION
            || !is_array($values)
            || !array_is_list($values)
            || count($values) > self::MAX_OBJECTS
        ) {
            throw new \InvalidArgumentException('Inventář strojových dat má neplatnou obálku.');
        }

        $required = self::requiredDefinitions($registry);
        $objects = [];
        $objectsByRegistryKey = [];
        foreach ($values as $index => $value) {
            $registryKey = is_array($value) ? ($value['registry_key'] ?? null) : null;
            if (!is_string($registryKey)
                || !isset($required[$registryKey])
                || isset($objectsByRegistryKey[$registryKey])
            ) {
                $label = is_string($registryKey) ? $registryKey : '(neplatný klíč)';
                throw new \InvalidArgumentException(
                    'Datový objekt ' . $label . ' není jedinečný obnovitelný objekt registru.',
                );
            }
            $object = CompanyBackupDataObject::fromArray(
                $value,
                $required[$registryKey],
                $index + 1,
            );
            $objects[] = $object;
            $objectsByRegistryKey[$registryKey] = $object;
        }

        $missing = array_diff_key($required, $objectsByRegistryKey);
        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Inventář strojových dat neobsahuje povinný objekt '
                . array_key_first($missing) . '.',
            );
        }

        return new self($objects, $objectsByRegistryKey, $registry->fingerprint);
    }

    public function object(string $registryKey): ?CompanyBackupDataObject
    {
        return $this->objectsByRegistryKey[$registryKey] ?? null;
    }

    /**
     * @param array<string,string> $entryHashes
     * @param array<string,int> $entryBytes
     */
    public function assertArchiveEntries(array $entryHashes, array $entryBytes): void
    {
        $actualPaths = array_values(array_filter(
            array_keys($entryHashes),
            static fn (string $path): bool => str_starts_with($path, 'data/'),
        ));
        $expectedPaths = array_map(
            static fn (CompanyBackupDataObject $object): string => $object->path,
            $this->objects,
        );
        sort($actualPaths, SORT_STRING);
        sort($expectedPaths, SORT_STRING);
        if ($actualPaths !== $expectedPaths) {
            throw new CompanyBackupArchiveException('data_inventory_scope_mismatch');
        }

        foreach ($this->objects as $object) {
            $actualHash = $entryHashes[$object->path] ?? null;
            if (!is_string($actualHash) || !hash_equals($object->sha256, $actualHash)) {
                throw new CompanyBackupArchiveException(
                    'data_entry_checksum_mismatch',
                    $object->path,
                );
            }
            if (($entryBytes[$object->path] ?? null) !== $object->bytes) {
                throw new CompanyBackupArchiveException(
                    'data_entry_size_mismatch',
                    $object->path,
                );
            }
        }
    }

    /** @return array{format:string,version:int,objects:list<array<string,mixed>>} */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'objects' => array_map(
                static fn (CompanyBackupDataObject $object): array => $object->toArray(),
                $this->objects,
            ),
        ];
    }

    /** @return array<string,TenantDataDefinition> */
    private static function requiredDefinitions(TenantDataRegistrySnapshot $snapshot): array
    {
        $required = [];
        foreach ($snapshot->registry->definitionsFor($snapshot->profile) as $definition) {
            if (in_array(
                $definition->kind,
                [TenantDataObjectKind::Table, TenantDataObjectKind::LogicalObject],
                true,
            ) && $definition->policy->hasMachineDataPayload()) {
                $required[$definition->key] = $definition;
            }
        }
        return $required;
    }
}
