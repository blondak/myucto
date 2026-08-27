<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;

/** Uzavřený DB snapshot připravený k přidání do šifrovaného archivu. */
final readonly class CompanyBackupMachineSnapshot
{
    /** @var array<string,string> cesta v archivu => lokální plaintext mezisoubor */
    public array $sourceFiles;

    /** @param array<string,string> $sourceFiles */
    public function __construct(
        public int $supplierId,
        public TenantDataRegistrySnapshot $registry,
        public CompanyBackupDataInventory $inventory,
        array $sourceFiles,
    ) {
        if ($supplierId < 1
            || !hash_equals($registry->fingerprint, $inventory->registryFingerprint)
        ) {
            throw new \InvalidArgumentException('Strojový snapshot nemá platnou obálku.');
        }
        $expectedPaths = array_map(
            static fn (CompanyBackupDataObject $object): string => $object->path,
            $inventory->objects,
        );
        if (array_keys($sourceFiles) !== $expectedPaths) {
            throw new \InvalidArgumentException(
                'Strojový snapshot nemá úplnou sadu zdrojových souborů.',
            );
        }
        foreach ($inventory->objects as $object) {
            $sourcePath = $sourceFiles[$object->path] ?? null;
            clearstatcache(true, is_string($sourcePath) ? $sourcePath : '');
            $size = is_string($sourcePath) ? @filesize($sourcePath) : false;
            if (!is_string($sourcePath)
                || !is_file($sourcePath)
                || is_link($sourcePath)
                || !is_int($size)
                || $size !== $object->bytes
            ) {
                throw new \InvalidArgumentException(
                    'Strojový snapshot obsahuje neplatný plaintext mezisoubor.',
                );
            }
        }
        $this->sourceFiles = $sourceFiles;
    }
}
