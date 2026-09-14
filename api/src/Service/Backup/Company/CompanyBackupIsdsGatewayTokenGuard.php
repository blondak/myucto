<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use PDO;

/** Historický app_token relace brány zůstává stejný i při obnově. */
final class CompanyBackupIsdsGatewayTokenGuard
{
    public const REGISTRY_KEY = 'table:isds_gateway_sessions';
    public const COLLISION = 'isds_gateway_token_collision';

    /** @param array<string,mixed> $row */
    public static function assertAvailable(PDO $database, string $registryKey, array $row): void
    {
        if ($registryKey !== self::REGISTRY_KEY) {
            return;
        }
        $token = $row['app_token'] ?? null;
        if (!is_string($token) || preg_match('/\A[0-9]{10,20}\z/D', $token) !== 1) {
            throw new CompanyBackupPreflightException(
                'isds_gateway_token_invalid', self::REGISTRY_KEY, 'app_token',
            );
        }
        // Bez supplier filtru: UNIQUE klíč i callback platí pro celou instanci.
        $statement = $database->prepare(
            'SELECT 1 FROM isds_gateway_sessions WHERE app_token = ? LIMIT 1',
        );
        $statement->execute([$token]);
        if ($statement->fetchColumn() !== false) {
            // Chyba neobsahuje token ani identitu vlastníka existující relace.
            throw new CompanyBackupPreflightException(
                self::COLLISION, self::REGISTRY_KEY, 'app_token',
            );
        }
    }
}
