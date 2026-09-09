<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Submission\SubmissionRestoreReview;

/** Stav komunikace po přenosu nelze dovozovat z výsledku na původní instanci. */
final class CompanyBackupIsdsRestorePolicy
{
    public const REVIEW_REQUIRED = SubmissionRestoreReview::CODE;
    public const MESSAGE = SubmissionRestoreReview::MESSAGE;

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
