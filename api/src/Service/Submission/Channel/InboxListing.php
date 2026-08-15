<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel;

/**
 * Seznam nových zpráv ve schránce.
 *
 * Existence téhle instance JE tvrzení „dotaz na schránku proběhl". Neúspěšný
 * dotaz se nedá vyjádřit — musí skončit {@see SubmissionChannelException}.
 * Bez toho by prázdný seznam znamenal dvě neslučitelné věci: „ve schránce nic
 * není" a „na schránku se nedovoláme". Ta druhá by tiše zastavila vyzvedávání
 * protokolů a nikdo by si toho nevšiml, dokud by nepropadla lhůta.
 */
final readonly class InboxListing
{
    /** @param list<InboxMessageHeader> $messages */
    public function __construct(
        public array $messages,
        public \DateTimeImmutable $polledAt,
    ) {}

    public function isEmpty(): bool
    {
        return $this->messages === [];
    }

    public function count(): int
    {
        return count($this->messages);
    }
}
