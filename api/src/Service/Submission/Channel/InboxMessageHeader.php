<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel;

/** Hlavička nové zprávy ve schránce — co víme, než ji stáhneme. */
final readonly class InboxMessageHeader
{
    public function __construct(
        public string $externalMessageId,
        public ?string $senderBoxId = null,
        public ?string $senderName = null,
        public ?string $subject = null,
        /**
         * `dmSenderIdent` / `dmRecipientIdent` přijaté zprávy.
         *
         * Náš klíč podání posíláme ven v `dmSenderIdent`, ale že ho úřad
         * v odpovědi zopakuje, není zaručeno. Párování přes tenhle údaj je
         * proto šťastná shoda, ne pravidlo — a když chybí, zpráva skončí
         * v „nezařazeno". To je správný výsledek: špatně navázaná zpráva
         * tvrdí něco o podání, o kterém nic neví.
         */
        public ?string $senderIdent = null,
        public ?\DateTimeImmutable $deliveredAt = null,
        public ?\DateTimeImmutable $acceptedAt = null,
    ) {}
}
