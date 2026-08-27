<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Platformně neutrální allowlist cest uvnitř přenositelného ZIPu. */
final class CompanyBackupArchivePath
{
    private const MAX_PATH_BYTES = 512;
    private const MAX_SEGMENT_BYTES = 128;

    public static function normalize(string $path, bool $directory): string
    {
        $hasTrailingSlash = str_ends_with($path, '/');
        if ($path === ''
            || strlen($path) > self::MAX_PATH_BYTES
            || $hasTrailingSlash !== $directory
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || preg_match('/\A[A-Za-z]:/', $path) === 1
            || preg_match('/\A[A-Za-z0-9._\/-]+\z/D', $path) !== 1
        ) {
            self::fail($path);
        }

        $normalized = $directory ? substr($path, 0, -1) : $path;
        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if ($segment === ''
                || $segment === '.'
                || $segment === '..'
                || strlen($segment) > self::MAX_SEGMENT_BYTES
                || str_ends_with($segment, '.')
                || str_ends_with($segment, ' ')
            ) {
                self::fail($path);
            }
            $basename = explode('.', $segment, 2)[0];
            if (preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])\z/Di', $basename) === 1) {
                self::fail($path);
            }
        }
        return $normalized;
    }

    public static function collisionKey(string $normalizedPath): string
    {
        return strtolower($normalizedPath);
    }

    private static function fail(string $path): never
    {
        throw new CompanyBackupArchiveException('entry_path_unsafe', $path);
    }
}
