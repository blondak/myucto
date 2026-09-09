<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use PDO;

/** Historický dmSenderIdent se nesmí přegenerovat ani sdílet s jinou kopií. */
final class CompanyBackupSubmissionCorrelationGuard
{
    public const REGISTRY_KEY = 'table:submission_outbox';
    public const COLLISION = 'submission_correlation_collision';

    /** @param array<string,mixed> $row */
    public static function assertAvailable(PDO $database, string $registryKey, array $row): void
    {
        if ($registryKey !== self::REGISTRY_KEY) {
            return;
        }
        $reference = $row['correlation_reference'] ?? null;
        if (!is_string($reference)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,49}\z/D', $reference) !== 1) {
            throw new CompanyBackupPreflightException(
                'submission_correlation_invalid', self::REGISTRY_KEY, 'correlation_reference',
            );
        }
        // Bez supplier filtru: databázová unikátnost platí pro celou instanci.
        $statement = $database->prepare(
            'SELECT 1 FROM submission_outbox WHERE correlation_reference = ? LIMIT 1',
        );
        $statement->execute([$reference]);
        if ($statement->fetchColumn() !== false) {
            // Chyba neprozrazuje značku, vlastníka ani ID cizího podání.
            throw new CompanyBackupPreflightException(
                self::COLLISION, self::REGISTRY_KEY, 'correlation_reference',
            );
        }
    }
}
