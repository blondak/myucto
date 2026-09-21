<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Mění jen živá ID; historický marker profilu a všechny ostatní bajty ponechá. */
final class CompanyBackupInvoiceSupplierSnapshotRemapper
{
    /**
     * Přenese výsledek společného mapování zpět do původních JSON bajtů.
     * Null u původně kladného ID znamená dočasný odklad v prvním průchodu.
     *
     * @param array<string,mixed> $mapped
     */
    public static function rewriteMapped(string $source, int $sourceSupplierId, array $mapped): string
    {
        $references = CompanyBackupInvoiceSupplierSnapshotContract::inspect($source, $sourceSupplierId);
        if ($references === null) {
            throw self::invalid('invoice_supplier_snapshot_invalid');
        }
        $replacements = [];
        foreach (['id' => 'supplier_id', 'email_profile_id' => 'email_profile_id'] as $path => $key) {
            if ($references[$key] === null) {
                continue;
            }
            if (!array_key_exists($path, $mapped)) {
                throw self::invalid('invoice_supplier_snapshot_mapping_missing');
            }
            $target = $mapped[$path];
            if ($target !== null && (!is_int($target) || $target < 1)) {
                throw self::invalid('invoice_supplier_snapshot_mapping_invalid');
            }
            $replacements[$path] = $target ?? CompanyBackupReferenceRemapDirective::Defer;
        }
        try {
            return CompanyBackupLosslessJson::rewriteIntegerTokens(
                $source,
                static fn (array $path, string $_token): int|CompanyBackupReferenceRemapDirective|null =>
                    count($path) === 1 && is_string($path[0])
                        ? ($replacements[$path[0]] ?? null)
                        : null,
            );
        } catch (\Throwable $e) {
            throw self::invalid('invoice_supplier_snapshot_invalid', $e);
        }
    }

    /**
     * Volající dodává mapování z ověřených tenantových identit; samotný helper
     * nedokazuje oprávnění cílového ID. Hodnoty kontroluje i za běhu.
     *
     * @param array<int,mixed> $emailProfileIds source ID => target ID
     */
    public static function rewrite(
        ?string $json,
        int $sourceSupplierId,
        int $targetSupplierId,
        array $emailProfileIds,
    ): ?string {
        if ($targetSupplierId < 1) {
            throw self::invalid('invoice_supplier_snapshot_target_invalid');
        }
        $references = CompanyBackupInvoiceSupplierSnapshotContract::inspect($json, $sourceSupplierId);
        if ($references === null || $json === null) {
            return null;
        }
        $emailSourceId = $references['email_profile_id'];
        $emailTargetId = null;
        if ($emailSourceId !== null) {
            if (!array_key_exists($emailSourceId, $emailProfileIds)) {
                throw self::invalid('invoice_supplier_snapshot_email_mapping_missing');
            }
            $emailTargetId = $emailProfileIds[$emailSourceId];
            if (!is_int($emailTargetId) || $emailTargetId < 1) {
                throw self::invalid('invoice_supplier_snapshot_email_mapping_invalid');
            }
        }
        try {
            return CompanyBackupLosslessJson::rewriteIntegerTokens(
                $json,
                static function (array $path, string $_token) use ($targetSupplierId, $emailTargetId): ?int {
                    return match ($path) {
                        ['id'] => $targetSupplierId,
                        ['email_profile_id'] => $emailTargetId,
                        default => null,
                    };
                },
            );
        } catch (\Throwable $e) {
            throw self::invalid('invoice_supplier_snapshot_invalid', $e);
        }
    }

    private static function invalid(string $code, ?\Throwable $previous = null): CompanyBackupPreflightException
    {
        return new CompanyBackupPreflightException(
            $code,
            CompanyBackupInvoiceSupplierSnapshotContract::REGISTRY_KEY,
            CompanyBackupInvoiceSupplierSnapshotContract::COLUMN,
            $previous,
        );
    }
}
