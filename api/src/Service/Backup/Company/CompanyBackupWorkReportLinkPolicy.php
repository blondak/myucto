<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Neaktivní politika trvalých odkazů. Volající musí detekovat kolize (včetně
 * revokovaných a duplicit v archivu), svázat rozhodnutí s preflightem a ověřit
 * unikátnost výsledku při INSERT. Tato politika sama databázi nečte ani nemění.
 */
final class CompanyBackupWorkReportLinkPolicy
{
    public const REGISTRY_KEY = 'table:work_report_links';
    public const COLUMN = 'token';

    public static function restoreToken(
        #[\SensitiveParameter] mixed $token,
        bool $collision,
        ?string $decision = null,
    ): string {
        // Stejný formát jako WorkReportLinkRepository::create (24 náhodných bajtů).
        if (!is_string($token) || preg_match('/\A[0-9a-f]{48}\z/D', $token) !== 1) {
            throw self::invalid('work_report_link_token_invalid');
        }
        if ($decision !== null && $decision !== 'regenerate' && $decision !== 'reject') {
            throw self::invalid('work_report_link_decision_invalid');
        }
        if (!$collision) {
            if ($decision !== null) {
                throw self::invalid('work_report_link_decision_out_of_scope');
            }
            return $token;
        }
        if ($decision === null) {
            throw self::invalid('work_report_link_decision_required');
        }
        if ($decision === 'reject') {
            throw self::invalid('work_report_link_collision_rejected');
        }
        do {
            $replacement = bin2hex(random_bytes(24));
        } while (hash_equals($token, $replacement));

        return $replacement;
    }

    private static function invalid(string $code): CompanyBackupPreflightException
    {
        return new CompanyBackupPreflightException($code, self::REGISTRY_KEY, self::COLUMN);
    }
}
