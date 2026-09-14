<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

/** Stejný klíč pro běžné zařazení i přepočet po přemapování ID v obnově. */
final class SubmissionOutboxIdentity
{
    public static function key(
        int $supplierId,
        string $environment,
        string $channel,
        string $agendaCode,
        string $artifactKind,
        int $artifactId,
        string $artifactSha256,
        ?int $recipientId,
    ): string {
        return implode('|', ['submission-outbox.v1', $supplierId, $environment,
            $channel, $agendaCode, $artifactKind, $artifactId, $artifactSha256, $recipientId ?? 0]);
    }
}
