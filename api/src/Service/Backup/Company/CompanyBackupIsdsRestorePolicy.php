<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Submission\SubmissionRestoreReview;
use MyInvoice\Service\Submission\Channel\DispatchState;

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

    /**
     * @param array<string,mixed> $sourceRow Původní řádek submission_outbox
     * @return array<string,mixed> Řádek určený k obnově, bez změny zdroje
     *
     * Příznak musí volající odvodit z původních stavů gateway v archivu, ještě
     * před gatewayOverrides(). Potvrzená sent/delivered historie má přednost
     * i tehdy, pokud v archivu zůstala starší rozpracovaná gateway relace.
     */
    public static function reviewOutboxRow(array $sourceRow, bool $hasAwaitingGatewaySession): array
    {
        $channel = $sourceRow['channel'] ?? null;
        if (!in_array($channel, ['epo', 'isds'], true)) {
            throw new CompanyBackupRowTransformException(
                'submission_channel_invalid',
                'table:submission_outbox',
                'channel',
            );
        }

        $rawState = $sourceRow['dispatch_state'] ?? null;
        $state = is_string($rawState) ? DispatchState::tryFrom($rawState) : null;
        if ($state === null) {
            throw new CompanyBackupRowTransformException(
                'submission_dispatch_state_invalid',
                'table:submission_outbox',
                'dispatch_state',
            );
        }

        if ($channel === 'epo') {
            return $sourceRow;
        }

        $requiresReview = match ($state) {
            DispatchState::Ready => $hasAwaitingGatewaySession,
            DispatchState::Sending, DispatchState::SendUncertain => true,
            DispatchState::Sent, DispatchState::Delivered, DispatchState::Failed, DispatchState::Cancelled => false,
        };
        if (!$requiresReview) {
            return $sourceRow;
        }

        $restoredRow = $sourceRow;
        $restoredRow['last_error_code'] = self::REVIEW_REQUIRED;
        $restoredRow['last_error_message'] = self::MESSAGE;
        return $restoredRow;
    }
}
