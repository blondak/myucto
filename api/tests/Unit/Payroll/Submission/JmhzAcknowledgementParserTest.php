<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzAcknowledgementParser;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use PHPUnit\Framework\TestCase;

/**
 * Vzorek je zkrácená, ale doslovná odpověď testovacího VREP na skutečně
 * odeslané podání — proto se tenhle tvar nehádá.
 */
final class JmhzAcknowledgementParserTest extends TestCase
{
    private const CORRELATION = '756E4B351F154858B19BC5EAF785BCC8';

    public function testRealAcknowledgementIsRead(): void
    {
        $ack = (new JmhzAcknowledgementParser())->parse(
            self::acknowledgement(),
            'CSSZ_JMHZ',
        );

        self::assertNotNull($ack);
        self::assertSame(self::CORRELATION, $ack->correlationId);
        self::assertSame('https://t-epodani.cssz.cz/VREP/poll', $ack->pollEndpoint);
        self::assertSame(60, $ack->pollIntervalSeconds);
        self::assertSame('CSSZ_JMHZ', $ack->submissionClass);
    }

    /**
     * Protokol o zpracování má vlastní parser. Kdyby ho tenhle vzal jako
     * potvrzení převzetí, výsledek zpracování by se zahodil a podání by
     * navždy čekalo na odpověď, která už přišla.
     */
    public function testProcessingProtocolIsNotAnAcknowledgement(): void
    {
        self::assertNull(
            (new JmhzAcknowledgementParser())->parse(
                JmhzTransportSample::partialProtocol(),
            ),
        );
    }

    public function testForeignSubmissionClassIsRefused(): void
    {
        $this->expectException(JmhzTransportException::class);
        $this->expectExceptionMessageMatches('/jiné třídy podání/');

        (new JmhzAcknowledgementParser())->parse(
            self::acknowledgement(),
            'CSSZ_REGZEC',
        );
    }

    /**
     * Potvrzení bez CorrelationID by znamenalo podání, které je u ČSSZ a
     * aplikace ho už nikdy nedohledá ani neuzavře.
     */
    public function testAcknowledgementWithoutCorrelationIsRefused(): void
    {
        $this->expectException(JmhzTransportException::class);
        $this->expectExceptionMessageMatches('/nedalo dohledat/');

        (new JmhzAcknowledgementParser())->parse(str_replace(
            '<CorrelationID>' . self::CORRELATION . '</CorrelationID>',
            '<CorrelationID></CorrelationID>',
            self::acknowledgement(),
        ));
    }

    /**
     * Adresa pro dotaz přichází v odpovědi. Poslat na ni podepsaný dotaz jen
     * proto, že to protistrana napsala, znamená důvěřovat cizímu vstupu při
     * volbě cíle.
     */
    public function testPollEndpointOutsideHttpsIsRefused(): void
    {
        $this->expectException(JmhzTransportException::class);
        $this->expectExceptionMessageMatches('/mimo HTTPS/');

        (new JmhzAcknowledgementParser())->parse(str_replace(
            'https://t-epodani.cssz.cz/VREP/poll',
            'http://evil.example/VREP/poll',
            self::acknowledgement(),
        ));
    }

    public function testUnreadableXmlIsRefused(): void
    {
        $this->expectException(JmhzTransportException::class);

        (new JmhzAcknowledgementParser())->parse('<GovTalkMessage');
    }

    private static function acknowledgement(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
            . '<EnvelopeVersion>2.0</EnvelopeVersion>'
            . '<Header><MessageDetails>'
            . '<Class>CSSZ_JMHZ</Class>'
            . '<Qualifier>acknowledgement</Qualifier>'
            . '<Function>submit</Function>'
            . '<TransactionID />'
            . '<CorrelationID>' . self::CORRELATION . '</CorrelationID>'
            . '<ResponseEndPoint PollInterval="60">https://t-epodani.cssz.cz/VREP/poll</ResponseEndPoint>'
            . '<GatewayTimestamp>2026-08-15T02:24:15.182</GatewayTimestamp>'
            . '</MessageDetails><SenderDetails /></Header>'
            . '<GovTalkDetails><Keys /></GovTalkDetails>'
            . '<Body />'
            . '</GovTalkMessage>';
    }
}
