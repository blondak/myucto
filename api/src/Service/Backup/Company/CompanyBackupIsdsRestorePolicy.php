<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stav komunikace po přenosu nelze dovozovat z výsledku na původní instanci. */
final class CompanyBackupIsdsRestorePolicy
{
    public const REVIEW_REQUIRED = 'company_backup_isds_review_required';
    public const MESSAGE = 'Po obnově zálohy není výsledek rozpracovaného odeslání ověřený. '
        . 'Před případným opakováním zkontrolujte odeslané zprávy v datové schránce.';

    /** @return array<string,array<string,mixed>> */
    public static function gatewayOverrides(): array
    {
        $when = ['column' => 'state', 'values' => ['awaiting_login', 'awaiting_approval']];
        return [
            'state' => ['value' => 'uncertain', 'reason' => self::REVIEW_REQUIRED, 'when' => $when],
            'error_code' => ['value' => self::REVIEW_REQUIRED, 'reason' => self::REVIEW_REQUIRED, 'when' => $when],
            'error_message' => ['value' => self::MESSAGE, 'reason' => self::REVIEW_REQUIRED, 'when' => $when],
        ];
    }
}
