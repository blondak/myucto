<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Pdf\PdfArchiveLayout;

final class CompanyBackupInvoicePdfFilePath
{
    public static function sourcePath(mixed $filename, int $supplierId): string
    {
        self::assertPositiveSupplierId($supplierId);
        $name = CompanyBackupFileBasename::validate($filename);
        $month = PdfArchiveLayout::monthSubdirectory($name);
        return self::prefix($supplierId) . ($month !== '' ? $month . '/' : '') . $name;
    }

    /** @return list<string> */
    public static function sourceCandidates(mixed $filename, int $supplierId): array
    {
        $canonical = self::sourcePath($filename, $supplierId);
        $name = CompanyBackupFileBasename::validate($filename);
        $flat = self::prefix($supplierId) . $name;
        return $canonical === $flat ? [$flat] : [$canonical, $flat];
    }

    public static function accepts(string $path, int $supplierId): bool
    {
        return self::parts($path, $supplierId) !== null;
    }

    public static function storedFilename(string $path, int $supplierId): string
    {
        $parts = self::parts($path, $supplierId);
        if ($parts === null) {
            throw self::invalid();
        }
        return $parts['filename'];
    }

    public static function restoreTargetPath(
        string $sourcePath,
        int $sourceSupplierId,
        int $targetSupplierId,
    ): string {
        return self::sourcePath(
            self::storedFilename($sourcePath, $sourceSupplierId),
            $targetSupplierId,
        );
    }

    /** @return null|array{filename:string} */
    private static function parts(string $path, int $supplierId): ?array
    {
        if ($supplierId < 1) {
            return null;
        }
        try {
            CompanyBackupFileEntry::normalizeSourcePath($path);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $prefix = self::prefix($supplierId);
        if (!str_starts_with($path, $prefix)) {
            return null;
        }
        $relative = substr($path, strlen($prefix));
        $segments = explode('/', $relative);
        if (count($segments) === 1) {
            $filename = $segments[0];
        } elseif (count($segments) === 2) {
            [$month, $filename] = $segments;
            if ($month !== PdfArchiveLayout::monthSubdirectory($filename) || $month === '') {
                return null;
            }
        } else {
            return null;
        }
        try {
            $filename = CompanyBackupFileBasename::validate($filename);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return ['filename' => $filename];
    }

    private static function prefix(int $supplierId): string
    {
        return 'sup-' . $supplierId . '/_archive/';
    }

    private static function assertPositiveSupplierId(int $supplierId): void
    {
        if ($supplierId < 1) {
            throw self::invalid();
        }
    }

    private static function invalid(): \InvalidArgumentException
    {
        return new \InvalidArgumentException('Cesta archivovaného PDF faktury není platná.');
    }
}
