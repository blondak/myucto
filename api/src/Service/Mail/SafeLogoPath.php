<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Validuje + resolvuje `supplier.logo_path` na bezpečnou absolutní cestu.
 *
 * Defense-in-depth proti LFI přes podstrčený `logo_path` (security report
 * @andrejtomci #2, CVE-class CWE-915 + CWE-22). Hlavní obrana je odebrání
 * `logo_path` z mass-assignment whitelistu v `SettingsAction`, ale read
 * sinks (preview HTML + Mailer embed) mají vlastní validaci pro případ,
 * že by se hodnota dostala do DB jinou cestou (historická data, manuální
 * SQL, jiný service).
 *
 * Povolené tvary (SupplierLogoConverter::process pište jen tyhle):
 *   storage/supplier-logos/sup-{N}.png                       — logo dodavatele
 *   storage/supplier-logos/sup-{N}.svg                       — SVG sidecar (PDF)
 *   storage/supplier-logos/sup-{N}-brand-{P}-{hash12}.png    — logo brandingového profilu
 *   storage/supplier-logos/sup-{N}-brand-{P}-{hash12}.svg
 *
 * `{P}` je id brandingového profilu, `{hash12}` prvních 12 hex znaků SHA-256
 * obsahu — díky němu re-upload nepřepíše soubor, který drží starší snapshot.
 * Tenant se pořád pozná z `sup-{N}`, takže cizí logo nepropustíme.
 *
 * Vrací **absolutní cestu** pokud:
 *   - prefix odpovídá očekávanému dir
 *   - basename match `sup-{supplierId}[-brand-{P}-{hash12}].{ext}` (žádný traversal)
 *   - extension je v allowlistu
 *   - realpath() neutekl mimo RuntimePaths::storage('supplier-logos')
 *   - soubor reálně existuje
 *
 * Vrací null v ostatních případech (caller má fallback chování).
 */
final class SafeLogoPath
{
    private const ALLOWED_EXT = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
    private const SAFE_DIR    = 'storage/supplier-logos';

    /** Ověří tenantovou identitu a tvar cesty relativní k SAFE_DIR. */
    public static function isAllowedSourcePath(
        string $sourcePath,
        int $supplierId,
    ): bool {
        if ($sourcePath === ''
            || $supplierId <= 0
            || str_contains($sourcePath, "\0")
            || str_contains($sourcePath, '/')
            || str_contains($sourcePath, '\\')
        ) {
            return false;
        }
        $ext = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            return false;
        }
        return preg_match(
            '/^sup-' . $supplierId
            . '(?:-brand-[1-9][0-9]*-[a-f0-9]{12})?\.'
            . preg_quote($ext, '/') . '$/D',
            $sourcePath,
        ) === 1;
    }

    public static function resolve(?string $logoPath, int $supplierId): ?string
    {
        if ($logoPath === null || $logoPath === '' || $supplierId <= 0) return null;
        $logoPath = (string) $logoPath;

        // Null bytes + traversal patterns → reject výslovně.
        if (strpos($logoPath, "\0") !== false || strpos($logoPath, '..') !== false) return null;

        // Musí začínat očekávaným prefixem (relativně k rootDir, bez leading /)
        $rel = ltrim($logoPath, '/');
        $expectedPrefix = self::SAFE_DIR . '/';
        if (!str_starts_with($rel, $expectedPrefix)) return null;

        $sourcePath = substr($rel, strlen($expectedPrefix));
        if (!self::isAllowedSourcePath($sourcePath, $supplierId)) return null;

        $rootDir = \MyInvoice\Infrastructure\Config\RuntimePaths::base();
        $abs = $rootDir . '/' . $rel;

        // realpath rejection (sym-link follow, real check that file is inside SAFE_DIR)
        $real = @realpath($abs);
        if ($real === false) return null;
        $safeBase = @realpath($rootDir . '/' . self::SAFE_DIR);
        if ($safeBase === false || !str_starts_with($real, $safeBase . DIRECTORY_SEPARATOR)) {
            return null;
        }
        if (!is_file($real)) return null;

        return $real;
    }
}
