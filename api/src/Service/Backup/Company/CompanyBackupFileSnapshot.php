<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Uzavřený souborový snapshot a lokální zdroje pro následný ZIP writer. */
final readonly class CompanyBackupFileSnapshot
{
    /** @var array<string,string> cesta v archivu => absolutní zdrojová cesta */
    public array $sourceFiles;

    /** @var array<string,string> cesta v archivu => relativní cesta oblasti */
    private array $sourcePaths;

    /**
     * @param array<mixed> $sourceFiles
     * @param array<mixed> $sourcePaths
     */
    public function __construct(
        public CompanyBackupFileInventory $inventory,
        array $sourceFiles,
        array $sourcePaths,
    ) {
        $expectedPaths = array_keys($inventory->archiveFiles());
        if (array_keys($sourceFiles) !== $expectedPaths
            || array_keys($sourcePaths) !== $expectedPaths
        ) {
            throw new \InvalidArgumentException(
                'Souborový snapshot nemá úplnou sadu zdrojových souborů.',
            );
        }
        $validatedSourceFiles = [];
        $validatedSourcePaths = [];
        foreach ($expectedPaths as $archivePath) {
            $sourceFile = $sourceFiles[$archivePath] ?? null;
            $sourcePath = $sourcePaths[$archivePath] ?? null;
            if (!is_string($sourceFile)
                || $sourceFile === ''
                || !is_string($sourcePath)
            ) {
                throw new \InvalidArgumentException(
                    'Souborový snapshot má neplatnou mapu zdrojů.',
                );
            }
            $validatedSourceFiles[$archivePath] = $sourceFile;
            $validatedSourcePaths[$archivePath] = $sourcePath;
        }
        $this->sourceFiles = $validatedSourceFiles;
        $this->sourcePaths = $validatedSourcePaths;
    }

    public function assertSourcesUnchanged(): void
    {
        foreach ($this->inventory->archiveFiles() as $archivePath => $expected) {
            $sourceFile = $this->sourceFiles[$archivePath];
            clearstatcache(true, $sourceFile);
            $before = @stat($sourceFile);
            $sha256 = $before === false || self::isSymlink($sourceFile)
                ? false
                : @hash_file('sha256', $sourceFile);
            clearstatcache(true, $sourceFile);
            $after = @stat($sourceFile);
            if ($before === false
                || $after === false
                || !is_string($sha256)
                || self::isSymlink($sourceFile)
                || !self::sameFile($before, $after)
                || $after['size'] !== $expected['bytes']
                || !hash_equals($expected['sha256'], $sha256)
            ) {
                throw new CompanyBackupFileSourceException(
                    'file_source_changed',
                    self::registryKey($archivePath),
                    $this->sourcePaths[$archivePath],
                );
            }
        }
    }

    /**
     * @param array<int|string,int> $before
     * @param array<int|string,int> $after
     */
    private static function sameFile(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /** @phpstan-impure */
    private static function isSymlink(string $path): bool
    {
        clearstatcache(true, $path);
        return is_link($path);
    }

    private static function registryKey(string $archivePath): string
    {
        $parts = explode('/', $archivePath, 3);
        return 'file-area:' . ($parts[1] ?? 'unknown');
    }
}
