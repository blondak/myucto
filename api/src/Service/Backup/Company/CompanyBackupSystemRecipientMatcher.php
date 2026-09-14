<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use PDO;

/**
 * Převáže archivní systémový číselník výhradně na existující systémový záznam cíle.
 * Identitou jsou code, kind, isds_box_id a business_id. Název, adresa, zdroj
 * ověření a auditní metadata nejsou identifikátory a cílový katalog se nemění.
 */
final class CompanyBackupSystemRecipientMatcher
{
    public const REGISTRY_KEY = 'table:submission_recipients';

    /**
     * @param array<string,mixed> $sourceRow
     * @return int Primární ID jediného odpovídajícího systémového záznamu cíle.
     */
    public static function match(PDO $database, array $sourceRow, bool $lockForUpdate = false): int
    {
        self::assertSource($sourceRow);
        if ($lockForUpdate && (!$database->inTransaction()
            || $database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql')) {
            throw new CompanyBackupPreflightException(
                'system_recipient_lock_unavailable', self::REGISTRY_KEY,
            );
        }

        // NULL ve složeném UNIQUE(supplier_id, code) dovoluje duplicitní
        // systémové řádky. LIMIT 2 odhalí nejednoznačnost před kontrolou identity.
        $statement = $database->prepare(
            'SELECT id, code, kind, isds_box_id, business_id
               FROM submission_recipients
              WHERE supplier_id IS NULL AND code = ?
              ORDER BY id
              LIMIT 2' . ($lockForUpdate ? ' FOR UPDATE' : ''),
        );
        $statement->execute([$sourceRow['code']]);
        $matches = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($matches === []) {
            throw new CompanyBackupPreflightException(
                'system_recipient_target_missing', self::REGISTRY_KEY, 'code',
            );
        }
        if (count($matches) !== 1) {
            throw new CompanyBackupPreflightException(
                'system_recipient_target_ambiguous', self::REGISTRY_KEY, 'code',
            );
        }

        $target = $matches[0];
        foreach (['code', 'kind', 'isds_box_id', 'business_id'] as $column) {
            if ($sourceRow[$column] !== $target[$column]) {
                throw new CompanyBackupPreflightException(
                    'system_recipient_target_mismatch', self::REGISTRY_KEY, $column,
                );
            }
        }
        $id = filter_var($target['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new CompanyBackupPreflightException(
                'system_recipient_target_mismatch', self::REGISTRY_KEY, 'id',
            );
        }
        return $id;
    }

    /** @param array<string,mixed> $sourceRow */
    private static function assertSource(array $sourceRow): void
    {
        if (!array_key_exists('supplier_id', $sourceRow) || $sourceRow['supplier_id'] !== null) {
            throw self::invalid('supplier_id');
        }
        if (!is_int($sourceRow['id'] ?? null) || $sourceRow['id'] < 1) {
            throw self::invalid('id');
        }
        if (!is_string($sourceRow['code'] ?? null)
            || preg_match('/\A[a-z][a-z0-9_]{1,47}\z/D', $sourceRow['code']) !== 1) {
            throw self::invalid('code');
        }
        if (!in_array($sourceRow['kind'] ?? null,
            ['tax_office', 'cssz', 'health_insurer', 'other'], true)) {
            throw self::invalid('kind');
        }
        foreach (['isds_box_id' => '/\A[a-z0-9]{7}\z/D',
            'business_id' => '/\A[0-9]{8}\z/D'] as $column => $pattern) {
            if (!array_key_exists($column, $sourceRow)
                || ($sourceRow[$column] !== null
                    && (!is_string($sourceRow[$column])
                        || preg_match($pattern, $sourceRow[$column]) !== 1))) {
                throw self::invalid($column);
            }
        }
    }

    private static function invalid(string $column): CompanyBackupPreflightException
    {
        return new CompanyBackupPreflightException(
            'system_recipient_source_invalid', self::REGISTRY_KEY, $column,
        );
    }
}
