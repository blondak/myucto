<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/** Zákaz opakování po obnově není nové potvrzení ani tvrzení o neodeslání. */
final class SubmissionRestoreReview
{
    public const CODE = 'company_backup_isds_review_required';
    public const MESSAGE = 'Po obnově zálohy není výsledek rozpracovaného odeslání ověřený. '
        . 'Před případným opakováním zkontrolujte odeslané zprávy v datové schránce.';

    /** @param array<string,mixed> $row */
    public static function assertDispatchAllowed(array $row): void
    {
        if (is_string($row['last_error_code'] ?? null)
            && strcasecmp($row['last_error_code'], self::CODE) === 0) {
            throw new SubmissionChannelException(self::CODE, self::MESSAGE, 409);
        }
    }

    /** Atomická pojistka síťového odesílání; ruční evidence hotové zprávy není send. */
    public static function sqlAllowsDispatch(): string
    {
        return "(last_error_code IS NULL OR LOWER(last_error_code) <> '" . self::CODE . "')";
    }
}
