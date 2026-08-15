<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Service\Submission\Channel\Epo\EpoChannel;
use MyInvoice\Service\Submission\Channel\Isds\IsdsChannel;
use MyInvoice\Service\Submission\Channel\SubmissionChannel;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\Channel\SubmissionInboxChannel;

/**
 * Jediné místo, kde se kód kanálu překládá na implementaci.
 *
 * Bez něj by `match ($channel)` vyrostl v každé službě zvlášť a přidání
 * třetího kanálu by znamenalo najít všechny — což je přesně ta třída chyb,
 * před kterou varuje AGENTS.md („kde jinde žije stejný koncept?").
 */
final readonly class SubmissionChannelRegistry
{
    public function __construct(
        private EpoChannel $epo,
        private IsdsChannel $isds,
    ) {}

    /** @return list<string> */
    public function codes(): array
    {
        return [EpoChannel::CODE, IsdsChannel::CODE];
    }

    public function get(string $code): SubmissionChannel
    {
        return match ($code) {
            EpoChannel::CODE => $this->epo,
            IsdsChannel::CODE => $this->isds,
            default => throw new SubmissionChannelException(
                'unknown_channel',
                'Neznámý kanál podání: ' . $code,
                400,
            ),
        };
    }

    /** Kanály s příchozí schránkou. EPO ji nemá. */
    public function inbox(string $code): SubmissionInboxChannel
    {
        return match ($code) {
            IsdsChannel::CODE => $this->isds,
            default => throw new SubmissionChannelException(
                'channel_has_no_inbox',
                'Kanál ' . $code . ' nemá příchozí schránku.',
                400,
            ),
        };
    }

    /** @return list<string> */
    public function inboxCodes(): array
    {
        return [IsdsChannel::CODE];
    }
}
