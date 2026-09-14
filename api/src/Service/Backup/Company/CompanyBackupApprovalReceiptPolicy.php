<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Zachovává hash spotřebovaného schválení. Volající musí ověřit kolizi i vazbu
 * rozhodnutí na preflight; tato čistá politika sama cílovou databázi nečte.
 */
final class CompanyBackupApprovalReceiptPolicy
{
    public const REGISTRY_KEY = 'table:invoices';
    public const COLUMN = 'approval_receipt_hash';

    public static function restoreHash(#[\SensitiveParameter] mixed $hash, bool $collision, ?string $decision = null): ?string
    {
        if ($hash !== null && (!is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/D', $hash) !== 1)) {
            throw self::invalid('approval_receipt_hash_invalid');
        }
        if ($decision !== null && $decision !== 'invalidate' && $decision !== 'reject') {
            throw self::invalid('approval_receipt_decision_invalid');
        }
        if ($hash === null && $collision) {
            throw self::invalid('approval_receipt_collision_context_invalid');
        }
        if (!$collision) {
            if ($decision !== null) {
                throw self::invalid('approval_receipt_decision_out_of_scope');
            }
            return $hash;
        }
        if ($decision === null) {
            throw self::invalid('approval_receipt_decision_required');
        }
        if ($decision === 'reject') {
            throw self::invalid('approval_receipt_collision_rejected');
        }
        return null;
    }

    private static function invalid(string $code): CompanyBackupPreflightException
    {
        return new CompanyBackupPreflightException($code, self::REGISTRY_KEY, self::COLUMN);
    }
}
