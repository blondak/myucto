<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use PDO;
use PDOException;

/** Neaktivní preflight dotaz pro globální UNIQUE token včetně revokovaných odkazů. */
final class CompanyBackupWorkReportLinkCollisionLookup
{
    public static function hasCollision(PDO $pdo, #[\SensitiveParameter] mixed $token): bool
    {
        $validated = CompanyBackupWorkReportLinkPolicy::restoreToken($token, false);
        try {
            // Stejné porovnání podle kolace sloupce jako uq_wrl_token při INSERT.
            $statement = $pdo->prepare('SELECT 1 FROM work_report_links WHERE token = ? LIMIT 1');
            if ($statement === false || !$statement->execute([$validated])) {
                throw self::failed();
            }
            return $statement->fetchColumn() !== false;
        } catch (PDOException) {
            throw self::failed();
        }
    }

    private static function failed(): CompanyBackupPreflightException
    {
        return new CompanyBackupPreflightException(
            'work_report_link_collision_lookup_failed',
            CompanyBackupWorkReportLinkPolicy::REGISTRY_KEY,
            CompanyBackupWorkReportLinkPolicy::COLUMN,
        );
    }
}
