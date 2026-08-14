<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use DOMDocument;

/**
 * Sestavená, ale ještě NEODESÍLATELNÁ obálka. Nepodepsané XML je schválně
 * pojmenované `unsignedXml`, aby ho nešlo omylem předat klientovi — cesta ven
 * vede jen přes `sendableXml()`, které bez podepisovací vrstvy skončí výjimkou.
 */
final readonly class JmhzGovTalkDocument
{
    public function __construct(
        public string $unsignedXml,
        public string $environment,
        public string $submissionClass,
        public string $variableSymbol,
    ) {}

    public function sha256(): string
    {
        return hash('sha256', $this->unsignedXml);
    }

    public function sendableXml(?JmhzEnvelopeSignerInterface $signer): string
    {
        if ($signer === null) {
            throw new JmhzTransportException(
                'jmhz_govtalk_signer_missing',
                'Obálku VREP nelze odeslat bez podepisovací vrstvy.',
            );
        }
        $signed = $signer->sign($this->unsignedXml);
        if (trim($signed) === '') {
            throw new JmhzTransportException(
                'jmhz_govtalk_signature_empty',
                'Podepisovací vrstva vrátila prázdnou obálku.',
            );
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $dom->loadXML($signed, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded
            || $dom->documentElement === null
            || $dom->documentElement->namespaceURI !== JmhzGovTalkEnvelope::NS_GOVTALK
            || $dom->documentElement->localName !== 'GovTalkMessage'
        ) {
            throw new JmhzTransportException(
                'jmhz_govtalk_signature_broke_envelope',
                'Podepsaná obálka už není platnou GovTalk zprávou.',
            );
        }

        return $signed;
    }
}
