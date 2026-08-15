<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds;

use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * Výchozí implementace {@see IsdsTransport}, dokud není rozhodnuto o knihovně.
 *
 * Nedělá nic a říká to nahlas. Je to lepší než kanál nezaregistrovat vůbec:
 * takhle je celý modul (fronta, ledger, číselník, trezor, UI, cron) plně
 * funkční a otestovaný, jen poslední krok — samotný odchod zprávy — hlásí
 * srozumitelnou překážku místo fatální chyby o chybějící službě.
 *
 * Do té doby platí, že skutečné odeslání dělá výhradně člověk ve své datové
 * schránce; fronta mu k tomu připraví artefakt, příjemce i spisovou značku.
 *
 * Žádná metoda nevrací prázdný výsledek — ani {@see listReceived()}, které by
 * prázdným polem tvrdilo „ve schránce nic nového není". To nevíme.
 */
final readonly class UnavailableIsdsTransport implements IsdsTransport
{
    private const MESSAGE = 'Napojení na datovou schránku zatím není nasazené. '
        . 'Podání je připravené — stáhněte si přílohu a odešlete ji ze své datové schránky ručně.';

    public function checkRecipientBox(ChannelContext $context, string $boxId): IsdsBoxCheck
    {
        throw $this->unavailable();
    }

    public function createMessage(
        ChannelContext $context,
        string $recipientBoxId,
        string $subject,
        string $senderIdent,
        array $files,
    ): IsdsSendReceipt {
        // Prokazatelné odmítnutí, ne nejistota: nic neodešlo, opakovat je bezpečné.
        throw $this->unavailable();
    }

    public function findSentBySenderIdent(ChannelContext $context, string $senderIdent): ?string
    {
        throw $this->unavailable();
    }

    public function messageState(ChannelContext $context, string $messageId): array
    {
        throw $this->unavailable();
    }

    public function listReceived(ChannelContext $context): array
    {
        throw $this->unavailable();
    }

    public function downloadMessage(ChannelContext $context, string $messageId): string
    {
        throw $this->unavailable();
    }

    public function downloadDeliveryReceipt(ChannelContext $context, string $messageId): ?string
    {
        throw $this->unavailable();
    }

    private function unavailable(): SubmissionChannelException
    {
        return new SubmissionChannelException('isds_transport_unavailable', self::MESSAGE, 503);
    }
}
