<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use DOMDocument;
use DOMXPath;

/**
 * Čtení potvrzení VREP o převzetí zprávy.
 *
 * Tvar je OVĚŘENÝ ODESLÁNÍM do testovacího prostředí: na podání i na dotaz
 * před dokončením zpracování vrací VREP obálku s `Qualifier=acknowledgement`,
 * přiděleným `CorrelationID`, adresou pro dotaz a doporučeným intervalem.
 * `ProcessingResult` v ní není — proto ji `JmhzProtocolParser` číst neumí
 * a nesmí: pro něj je to zpráva neznámého tvaru a fail-closed je správně.
 *
 * Bez `CorrelationID` se podání nedá dohledat ani uzavřít, takže jeho absence
 * je chyba, ne prázdná hodnota. Právě tady se ztracená podání dělají.
 */
final readonly class JmhzAcknowledgementParser
{
    private const QUALIFIER = 'acknowledgement';
    private const DEFAULT_POLL_INTERVAL = 60;

    /** Horní mez je pojistka proti obálce, která by dotaz odložila o týdny. */
    private const MAX_POLL_INTERVAL = 3600;

    /**
     * Vrátí potvrzení, nebo `null`, když zpráva potvrzením není — volající pak
     * ví, že má odpověď předat protokolovému parseru.
     */
    public function parse(string $xml, ?string $expectedClass = null): ?JmhzVrepAcknowledgement
    {
        $dom = $this->load($xml);
        $root = $dom->documentElement;
        if ($root === null
            || $root->localName !== 'GovTalkMessage'
            || $root->namespaceURI !== JmhzGovTalkEnvelope::NS_GOVTALK
        ) {
            return null;
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('g', JmhzGovTalkEnvelope::NS_GOVTALK);
        $details = '//g:Header/g:MessageDetails/';
        if (trim($this->text($xpath, $details . 'g:Qualifier')) !== self::QUALIFIER) {
            return null;
        }

        $class = trim($this->text($xpath, $details . 'g:Class'));
        // Potvrzení k jiné třídě podání znamená, že odpověď patří někomu
        // jinému. Přiřadit ji k našemu pokusu by uzavřelo cizí transakci.
        if ($expectedClass !== null && $class !== $expectedClass) {
            throw new JmhzTransportException(
                'jmhz_acknowledgement_class_mismatch',
                'Potvrzení VREP se týká jiné třídy podání, než jaká byla odeslána.',
            );
        }

        $correlation = trim($this->text($xpath, $details . 'g:CorrelationID'));
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $correlation) !== 1) {
            throw new JmhzTransportException(
                'jmhz_acknowledgement_correlation_missing',
                'Potvrzení VREP nenese použitelný CorrelationID; podání by se'
                    . ' už nedalo dohledat ani uzavřít.',
            );
        }

        $endpointNode = $xpath->query($details . 'g:ResponseEndPoint')->item(0);
        $endpoint = $endpointNode === null ? '' : trim($endpointNode->textContent);
        // Adresa pro dotaz se přebírá jen tehdy, když je to opravdu HTTPS URL.
        // Cokoli jiného by znamenalo poslat podepsaný dotaz tam, kam ukázala
        // odpověď — a tu jsme sami nesestavili.
        if ($endpoint !== '' && !str_starts_with($endpoint, 'https://')) {
            throw new JmhzTransportException(
                'jmhz_acknowledgement_endpoint_untrusted',
                'Potvrzení VREP odkazuje na dotazovací adresu mimo HTTPS.',
            );
        }

        $interval = self::DEFAULT_POLL_INTERVAL;
        if ($endpointNode instanceof \DOMElement
            && $endpointNode->hasAttribute('PollInterval')
        ) {
            $raw = trim($endpointNode->getAttribute('PollInterval'));
            if (preg_match('/^\d+$/D', $raw) === 1 && (int) $raw > 0) {
                $interval = min((int) $raw, self::MAX_POLL_INTERVAL);
            }
        }

        return new JmhzVrepAcknowledgement(
            $correlation,
            $endpoint,
            $interval,
            trim($this->text($xpath, $details . 'g:GatewayTimestamp')),
            $class,
        );
    }

    private function load(string $xml): DOMDocument
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new JmhzTransportException(
                'jmhz_acknowledgement_unreadable',
                'Odpověď VREP se nepodařilo přečíst jako XML.',
            );
        }

        return $dom;
    }

    private function text(DOMXPath $xpath, string $expression): string
    {
        $node = $xpath->query($expression)->item(0);

        return $node === null ? '' : $node->textContent;
    }
}
