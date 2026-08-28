<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;

/** Manifestový inventář všech obnovitelných souborových oblastí firmy. */
final readonly class CompanyBackupFileInventory
{
    public const FORMAT = 'myucto-company-file-inventory';
    public const VERSION = 1;
    private const MAX_AREAS = 1_000;

    /** @var list<CompanyBackupFileAreaInventory> */
    public array $areas;

    /**
     * @param list<CompanyBackupFileAreaInventory> $areas
     * @param array<string,array{sha256:string,bytes:int}> $archiveFiles
     */
    private function __construct(
        array $areas,
        public string $registryFingerprint,
        private array $archiveFiles,
    ) {
        $this->areas = $areas;
    }

    public static function fromArray(
        mixed $inventory,
        TenantDataRegistrySnapshot $registry,
    ): self {
        if (!is_array($inventory) || array_is_list($inventory)) {
            throw new \InvalidArgumentException('Inventář souborů musí být JSON objekt.');
        }
        $keys = array_keys($inventory);
        sort($keys, SORT_STRING);
        if ($keys !== ['areas', 'format', 'version']
            || $inventory['format'] !== self::FORMAT
            || $inventory['version'] !== self::VERSION
            || !is_array($inventory['areas'])
            || !array_is_list($inventory['areas'])
            || count($inventory['areas']) > self::MAX_AREAS
        ) {
            throw new \InvalidArgumentException('Inventář souborů má neplatnou obálku.');
        }

        $required = self::requiredDefinitions($registry);
        $areas = [];
        $seen = [];
        $archiveFiles = [];
        foreach ($inventory['areas'] as $index => $value) {
            $registryKey = is_array($value) ? ($value['registry_key'] ?? null) : null;
            if (!is_string($registryKey)
                || !isset($required[$registryKey])
                || isset($seen[$registryKey])
            ) {
                throw new \InvalidArgumentException(
                    'Souborová oblast inventáře není jedinečný objekt registru.',
                );
            }
            $area = CompanyBackupFileAreaInventory::fromArray(
                $value,
                $required[$registryKey],
                $index + 1,
                $registry->registry,
            );
            $areas[] = $area;
            $seen[$registryKey] = true;
            foreach ($area->entries as $entry) {
                if ($entry->state !== CompanyBackupFileState::Present) {
                    continue;
                }
                $path = $entry->archivePath;
                $sha256 = $entry->sha256;
                $bytes = $entry->bytes;
                if (!is_string($path) || !is_string($sha256) || !is_int($bytes)) {
                    throw new \LogicException('Existující soubor nemá úplná metadata.');
                }
                $known = $archiveFiles[$path] ?? null;
                if ($known !== null
                    && ($known['sha256'] !== $sha256 || $known['bytes'] !== $bytes)
                ) {
                    throw new \InvalidArgumentException(
                        'Jedna ZIP cesta souboru má rozporná metadata.',
                    );
                }
                $archiveFiles[$path] = ['sha256' => $sha256, 'bytes' => $bytes];
            }
        }
        $missing = array_diff_key($required, $seen);
        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Inventář souborů neobsahuje povinnou oblast '
                . array_key_first($missing) . '.',
            );
        }
        ksort($archiveFiles, SORT_STRING);
        return new self($areas, $registry->fingerprint, $archiveFiles);
    }

    /**
     * @param array<string,string> $entryHashes
     * @param array<string,int> $entryBytes
     */
    public function assertArchiveEntries(array $entryHashes, array $entryBytes): void
    {
        $actualPaths = array_values(array_filter(
            array_keys($entryHashes),
            static fn (string $path): bool => str_starts_with($path, 'files/'),
        ));
        $expectedPaths = array_keys($this->archiveFiles);
        sort($actualPaths, SORT_STRING);
        if ($actualPaths !== $expectedPaths) {
            throw new CompanyBackupArchiveException('file_inventory_scope_mismatch');
        }
        foreach ($this->archiveFiles as $path => $expected) {
            $hash = $entryHashes[$path] ?? null;
            if (!is_string($hash) || !hash_equals($expected['sha256'], $hash)) {
                throw new CompanyBackupArchiveException(
                    'file_entry_checksum_mismatch',
                    $path,
                );
            }
            if (($entryBytes[$path] ?? null) !== $expected['bytes']) {
                throw new CompanyBackupArchiveException(
                    'file_entry_size_mismatch',
                    $path,
                );
            }
        }
    }

    /** @return array<string,array{sha256:string,bytes:int}> */
    public function archiveFiles(): array
    {
        return $this->archiveFiles;
    }

    /** @return array{format:string,version:int,areas:list<array<string,mixed>>} */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'areas' => array_map(
                static fn (CompanyBackupFileAreaInventory $area): array => $area->toArray(),
                $this->areas,
            ),
        ];
    }

    /** @return array<string,TenantDataDefinition> */
    private static function requiredDefinitions(
        TenantDataRegistrySnapshot $snapshot,
    ): array {
        $required = [];
        foreach ($snapshot->registry->definitionsFor($snapshot->profile) as $definition) {
            if ($definition->kind === TenantDataObjectKind::FileArea
                && $definition->policy->hasMachineDataPayload()
            ) {
                CompanyBackupFilePolicy::fromDefinition($definition);
                $required[$definition->key] = $definition;
            }
        }
        return $required;
    }
}
