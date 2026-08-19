<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Service\Submission\Channel\OutboundSubmission;

/**
 * Hotová datová zpráva ISDS s podáním JMHZ — obsah i adresát.
 *
 * Je to čistá hodnota bez chování navíc, aby se dala vyrobit a zkontrolovat
 * bez sítě: v ručním režimu ji uživatel opisuje do své datové schránky, takže
 * musí být kompletní a stejná pro automat i pro člověka. Kdyby si každá cesta
 * skládala pole po svém, ručně odeslaná zpráva by nesla jiné údaje než ta
 * automatická a odpověď by se k podání nedohledala.
 */
final readonly class JmhzIsdsMessage
{
    public function __construct(
        public JmhzIsdsRecipient $recipient,
        public string $subject,
        public string $senderIdent,
        public string $attachmentFilename,
        public string $attachmentMimeType,
        public string $attachmentBytes,
    ) {}

    public function attachmentSha256(): string
    {
        return hash('sha256', $this->attachmentBytes);
    }

    /**
     * Převod na obecné podání platformy, které umí odeslat
     * {@see \MyInvoice\Service\Submission\Channel\Isds\IsdsChannel}.
     *
     * Existuje proto, aby JMHZ neměl vlastní odesílací cestu: kanál ISDS je
     * jeden pro celou aplikaci (DPH, DPPO, přehledy ZP i mzdy) a druhá cesta by
     * znamenala druhé místo, kde se dá splést adresát.
     */
    public function toOutboundSubmission(
        int $outboxId,
        int $supplierId,
        string $agendaCode,
    ): OutboundSubmission {
        return new OutboundSubmission(
            outboxId: $outboxId,
            supplierId: $supplierId,
            environment: $this->recipient->environment,
            agendaCode: $agendaCode,
            subject: $this->subject,
            recipientBoxId: $this->recipient->boxId,
            artifactFilename: $this->attachmentFilename,
            artifactMimeType: $this->attachmentMimeType,
            artifactBytes: $this->attachmentBytes,
            artifactSha256: $this->attachmentSha256(),
            correlationReference: $this->senderIdent,
        );
    }
}
