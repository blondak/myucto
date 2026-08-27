<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Jediná topologická kontrola cest ZIPu pro reader i writer. Vedle přesné
 * duplicity hlídá casing Windows a stav, kdy je soubor zároveň rodičem jiné
 * položky.
 */
final class CompanyBackupArchivePathSet
{
    /** @var array<string,array{path:string,directory:bool}> */
    private array $entries = [];

    /** @var array<string,true> normalizované prefixy, pod nimiž už něco leží */
    private array $parentKeys = [];

    public function add(string $rawPath, bool $directory): string
    {
        $path = CompanyBackupArchivePath::normalize($rawPath, $directory);
        $collisionKey = CompanyBackupArchivePath::collisionKey($path);
        if (isset($this->entries[$collisionKey])) {
            throw new CompanyBackupArchiveException(
                'entry_path_duplicate',
                $this->entries[$collisionKey]['path'],
            );
        }

        $segments = explode('/', $path);
        array_pop($segments);
        $prefix = '';
        foreach ($segments as $segment) {
            $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
            $prefixKey = CompanyBackupArchivePath::collisionKey($prefix);
            if (isset($this->entries[$prefixKey])
                && !$this->entries[$prefixKey]['directory']
            ) {
                throw new CompanyBackupArchiveException('entry_path_conflict', $path);
            }
        }
        if (!$directory && isset($this->parentKeys[$collisionKey])) {
            throw new CompanyBackupArchiveException('entry_path_conflict', $path);
        }

        $this->entries[$collisionKey] = [
            'path' => $path,
            'directory' => $directory,
        ];
        $prefix = '';
        foreach ($segments as $segment) {
            $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
            $this->parentKeys[CompanyBackupArchivePath::collisionKey($prefix)] = true;
        }
        return $path;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_column(array_values($this->entries), 'path');
    }
}
