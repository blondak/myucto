<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use PDO;
use PDOException;

/** Čte obě podoby stejného veřejného odkazu, i po spotřebování jeho tokenu. */
final class CompanyBackupApprovalReceiptCollisionLookup
{
    public static function hasCollision(PDO $pdo, #[\SensitiveParameter] mixed $hash): bool
    {
        $validated = CompanyBackupApprovalReceiptPolicy::restoreHash($hash, false);
        if ($validated === null) {
            return false;
        }
        try {
            // Kolize veřejného tokenu platí napříč firmami; stav ani expirace
            // nemění identitu odkazu.
            // Kontrola proto musí vidět celou instanci, ale nevrací vlastníka ani data.
            $statement = $pdo->prepare(
                'SELECT 1 FROM invoices WHERE approval_receipt_hash = ? OR SHA2(approval_token, 256) = ? LIMIT 1',
            );
            if ($statement === false || !$statement->execute([$validated, $validated])) {
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
            'approval_receipt_collision_lookup_failed',
            CompanyBackupApprovalReceiptPolicy::REGISTRY_KEY,
            CompanyBackupApprovalReceiptPolicy::COLUMN,
        );
    }
}
