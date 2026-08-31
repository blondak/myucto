<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;

/** Uzavřený strojový snapshot připravený k přidání do šifrovaného archivu. */
final readonly class CompanyBackupMachineSnapshot
{
    /** @var array<string,string> cesta v archivu => lokální zdroj */
    public array $sourceFiles;

    /** @var array<string,string> cesta dat v archivu => vlastněný plaintext temp */
    public array $temporarySourceFiles;

    /**
     * @param array<string,string> $sourceFiles
     * @param array<string,string> $temporarySourceFiles
     */
    public function __construct(
        public int $supplierId,
        public string $backupId,
        public TenantDataRegistrySnapshot $registry,
        public CompanyBackupDataInventory $inventory,
        public CompanyBackupFileInventory $fileInventory,
        public CompanyBackupSecretInventory $secretInventory,
        public ?CompanyBackupSealedSecretEnvelope $secretEnvelope,
        array $sourceFiles,
        array $temporarySourceFiles,
    ) {
        if ($supplierId < 1
            || !CompanyBackupManifestHeader::isCanonicalBackupId($backupId)
            || !hash_equals($registry->fingerprint, $inventory->registryFingerprint)
            || !hash_equals(
                $registry->fingerprint,
                $fileInventory->registryFingerprint,
            )
            || !hash_equals(
                $registry->fingerprint,
                $secretInventory->registryFingerprint,
            )
            || ($secretEnvelope === null) !== ($secretInventory->envelope === null)
            || ($secretEnvelope !== null
                && $secretInventory->envelope !== null
                && $secretEnvelope->descriptor->toArray()
                    !== $secretInventory->envelope->toArray())
        ) {
            throw new \InvalidArgumentException('Strojový snapshot nemá platnou obálku.');
        }
        $dataPaths = array_map(
            static fn (CompanyBackupDataObject $object): string => $object->path,
            $inventory->objects,
        );
        $expectedPaths = $dataPaths;
        array_push($expectedPaths, ...array_keys($fileInventory->archiveFiles()));
        if (array_keys($sourceFiles) !== $expectedPaths
            || array_keys($temporarySourceFiles) !== $dataPaths
        ) {
            throw new \InvalidArgumentException(
                'Strojový snapshot nemá úplnou sadu zdrojových souborů.',
            );
        }
        foreach ($inventory->objects as $object) {
            $sourcePath = $sourceFiles[$object->path] ?? null;
            if (($temporarySourceFiles[$object->path] ?? null) !== $sourcePath) {
                throw new \InvalidArgumentException(
                    'Strojový snapshot nemá přesné vlastnictví mezisouborů.',
                );
            }
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
        foreach ($fileInventory->archiveFiles() as $archivePath => $metadata) {
            $sourcePath = $sourceFiles[$archivePath] ?? null;
            clearstatcache(true, is_string($sourcePath) ? $sourcePath : '');
            $size = is_string($sourcePath) ? @filesize($sourcePath) : false;
            if (!is_string($sourcePath)
                || !is_file($sourcePath)
                || is_link($sourcePath)
                || !is_int($size)
                || $size !== $metadata['bytes']
            ) {
                throw new \InvalidArgumentException(
                    'Strojový snapshot obsahuje neplatný registrovaný soubor.',
                );
            }
        }
        $temporaryPathIdentities = [];
        foreach ($temporarySourceFiles as $sourcePath) {
            $identity = self::pathIdentity($sourcePath);
            if (isset($temporaryPathIdentities[$identity])) {
                throw new \InvalidArgumentException(
                    'Strojový snapshot sdílí jeden vlastněný mezisoubor.',
                );
            }
            $temporaryPathIdentities[$identity] = true;
        }
        foreach (array_diff_key($sourceFiles, $temporarySourceFiles) as $sourcePath) {
            if (isset($temporaryPathIdentities[self::pathIdentity($sourcePath)])) {
                throw new \InvalidArgumentException(
                    'Strojový snapshot zaměňuje vlastněný a vypůjčený soubor.',
                );
            }
        }
        $this->sourceFiles = $sourceFiles;
        $this->temporarySourceFiles = $temporarySourceFiles;
    }

    public function __destruct()
    {
        try {
            $this->cleanupTemporaryFiles();
        } catch (\Throwable) {
        }
    }

    public function cleanupTemporaryFiles(): void
    {
        $clean = true;
        foreach ($this->temporarySourceFiles as $sourcePath) {
            clearstatcache(true, $sourcePath);
            if (@lstat($sourcePath) === false) {
                continue;
            }
            if ((!is_file($sourcePath) && !is_link($sourcePath))
                || !@unlink($sourcePath)
            ) {
                $clean = false;
                continue;
            }
        }
        if (!$clean) {
            throw new CompanyBackupSnapshotException('snapshot_cleanup_failed');
        }
    }

    private static function pathIdentity(string $path): string
    {
        $resolved = realpath($path);
        return strtolower(str_replace(
            '\\',
            '/',
            is_string($resolved) ? $resolved : $path,
        ));
    }
}
