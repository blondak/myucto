<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Jedna cílová cesta souboru odvozená z ověřeného inventáře obnovy. */
final readonly class CompanyBackupFilePublicationEntry
{
    public function __construct(
        public string $registryKey,
        public string $storageSubdirectory,
        public string $sourcePath,
        public string $targetPath,
        public CompanyBackupFileState $state,
        public ?string $archivePath,
        public ?int $bytes,
        public ?string $sha256,
    ) {
        $sourcePath = CompanyBackupFileEntry::normalizeSourcePath($sourcePath);
        $targetPath = CompanyBackupFileEntry::normalizeSourcePath($targetPath);
        if (!str_starts_with($registryKey, 'file-area:')
            || $storageSubdirectory === ''
            || $sourcePath !== $this->sourcePath
            || $targetPath !== $this->targetPath
        ) {
            throw new \InvalidArgumentException(
                'Položka publication plánu souborů není platná.',
            );
        }
        if ($state === CompanyBackupFileState::Present) {
            if (!is_string($archivePath)
                || !str_starts_with($archivePath, 'files/')
                || !is_int($bytes)
                || $bytes < 0
                || !is_string($sha256)
                || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1
            ) {
                throw new \InvalidArgumentException(
                    'Existující položka publication plánu nemá úplná metadata.',
                );
            }
        } elseif ($archivePath !== null || $bytes !== null || $sha256 !== null) {
            throw new \InvalidArgumentException(
                'Chybějící položka publication plánu nesmí mít obsahová metadata.',
            );
        }
    }

    /** @return array<string,mixed> */
    public function bindingValue(): array
    {
        return [
            'registry_key' => $this->registryKey,
            'storage_subdirectory' => $this->storageSubdirectory,
            'source_path' => $this->sourcePath,
            'target_path' => $this->targetPath,
            'state' => $this->state->value,
            'archive_path' => $this->archivePath,
            'bytes' => $this->bytes,
            'sha256' => $this->sha256,
        ];
    }
}
