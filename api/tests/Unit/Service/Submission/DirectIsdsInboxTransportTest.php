<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\Isds\DirectIsdsInboxTransport;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use PHPUnit\Framework\TestCase;

final class DirectIsdsInboxTransportTest extends TestCase
{
    public function testPasswordLoginUsesTestEndpointAndParsesInbox(): void
    {
        $seenUrl = '';
        $seenBody = '';
        $transport = new DirectIsdsInboxTransport(
            static function (string $url, string $body) use (&$seenUrl, &$seenBody): array {
                $seenUrl = $url;
                $seenBody = $body;
                return ['status' => 200, 'body' => self::listResponse()];
            },
        );

        $rows = $transport->listReceived($this->passwordContext());

        self::assertSame('https://ws1.datovka-test.gov.cz/DS/dx', $seenUrl);
        self::assertStringContainsString('GetListOfReceivedMessages', $seenBody);
        self::assertStringContainsString('<isds:dmLimit>50</isds:dmLimit>', $seenBody);
        self::assertCount(1, $rows);
        self::assertSame('123456789', $rows[0]['message_id']);
        self::assertSame('Finanční úřad', $rows[0]['sender_name']);
    }

    public function testSignedMessageIsDecodedAsZfoBytes(): void
    {
        $zfo = "synthetic-zfo\0bytes";
        $transport = new DirectIsdsInboxTransport(
            static fn (): array => ['status' => 200, 'body' => self::downloadResponse($zfo)],
        );

        self::assertSame($zfo, $transport->downloadMessage($this->passwordContext(), '123456789'));
    }

    private function passwordContext(): ChannelContext
    {
        return new ChannelContext(
            7,
            'test',
            new ChannelCredentials(
                boxId: '',
                authMode: 'password',
                username: SensitiveValue::fromProducer(static fn (): string => 'synthetic-user'),
                password: SensitiveValue::fromProducer(static fn (): string => 'synthetic-password'),
            ),
        );
    }

    private static function listResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:isds="http://isds.czechpoint.cz/v20">
  <soap:Body><isds:GetListOfReceivedMessagesResponse>
    <isds:dmRecord><isds:dmID>123456789</isds:dmID><isds:dbIDSender>abcdefg</isds:dbIDSender><isds:dmSender>Finanční úřad</isds:dmSender><isds:dmAnnotation>Syntetická zpráva</isds:dmAnnotation><isds:dmDeliveryTime>2026-08-25T12:00:00+02:00</isds:dmDeliveryTime></isds:dmRecord>
    <isds:dmStatus><isds:dmStatusCode>0000</isds:dmStatusCode><isds:dmStatusMessage>OK</isds:dmStatusMessage></isds:dmStatus>
  </isds:GetListOfReceivedMessagesResponse></soap:Body>
</soap:Envelope>
XML;
    }

    private static function downloadResponse(string $bytes): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:isds="http://isds.czechpoint.cz/v20"><soap:Body>'
            . '<isds:SignedMessageDownloadResponse><isds:dmSignature>' . base64_encode($bytes) . '</isds:dmSignature>'
            . '<isds:dmStatus><isds:dmStatusCode>0000</isds:dmStatusCode><isds:dmStatusMessage>OK</isds:dmStatusMessage></isds:dmStatus>'
            . '</isds:SignedMessageDownloadResponse></soap:Body></soap:Envelope>';
    }
}
