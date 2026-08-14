<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifierInterface;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzReceiptVerifier;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use PHPUnit\Framework\TestCase;

final class JmhzReceiptVerifierTest extends TestCase
{
    private function signatures(): JmhzProtocolSignatureVerifierInterface
    {
        return new class implements JmhzProtocolSignatureVerifierInterface {
            public function verifiedProtocolXml(string $bytes, string $environment): string
            {
                return $bytes;
            }
        };
    }

    private function protocol(string $formResult = 'OK'): string
    {
        return JmhzTransportSample::partialProtocol(
            $formResult === 'OK' ? 'OK' : 'ERROR',
            [
                ['guid' => JmhzTransportSample::FORM_GUID, 'result' => 'OK'],
                [
                    'guid' => JmhzTransportSample::OTHER_FORM_GUID,
                    'result' => $formResult,
                    'errMsg' => $formResult === 'OK'
                        ? ''
                        : 'JMHZ25_LT: 20118 - Chybná hodnota',
                    'errNum' => $formResult === 'OK' ? '' : '20118',
                ],
            ],
            errMsg: $formResult === 'OK' ? '' : 'JMHZ25_LT: 20118 - Chybná hodnota',
            errNumber: $formResult === 'OK' ? '0' : '20118',
            correlationId: 'CID0000000001',
        );
    }

    public function testProtocolWithoutSignatureVerificationIsNeverTrusted(): void
    {
        try {
            (new JmhzReceiptVerifier())->verify(
                $this->protocol(),
                'vrep_apep',
                'test',
                null,
            );
            self::fail('Bez ověření podpisu nesmí verifier nic vrátit.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_signature_verifier_missing', $e->errorCode);
        }
    }

    public function testVerifiedProtocolBecomesAReceiptWithPartStatuses(): void
    {
        $verifier = (new JmhzReceiptVerifier($this->signatures()))->withFormPartIds([
            JmhzTransportSample::FORM_GUID => 11,
            JmhzTransportSample::OTHER_FORM_GUID => 12,
        ]);

        $receipt = $verifier->verify(
            $this->protocol('ERROR'),
            'vrep_apep',
            'test',
            'CID0000000001',
        );

        self::assertSame('partially_accepted', $receipt->remoteStatus);
        self::assertSame('CID0000000001', $receipt->correlationReference);
        self::assertSame([11 => 'accepted', 12 => 'rejected'], $receipt->partStatuses);
    }

    public function testReceiptWithoutAFormMapCarriesOnlyTheOverallStatus(): void
    {
        $receipt = (new JmhzReceiptVerifier($this->signatures()))->verify(
            $this->protocol(),
            'vrep_apep',
            'test',
            null,
        );

        self::assertSame('accepted', $receipt->remoteStatus);
        self::assertSame([], $receipt->partStatuses);
    }

    public function testProtocolForAnotherSubmissionIsRefused(): void
    {
        try {
            (new JmhzReceiptVerifier($this->signatures()))->verify(
                $this->protocol(),
                'vrep_apep',
                'test',
                'CID0000000009',
            );
            self::fail('Cizí protokol musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_correlation_mismatch', $e->errorCode);
        }
    }

    public function testForeignFormGuidIsRefusedWhenAMapIsDeclared(): void
    {
        $verifier = (new JmhzReceiptVerifier($this->signatures()))->withFormPartIds([
            JmhzTransportSample::FORM_GUID => 11,
        ]);

        try {
            $verifier->verify($this->protocol(), 'vrep_apep', 'test', null);
            self::fail('Neznámý GUID formuláře musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_form_unmapped', $e->errorCode);
        }
    }

    public function testOtherChannelsAreNotHandledHere(): void
    {
        try {
            (new JmhzReceiptVerifier($this->signatures()))->verify(
                $this->protocol(),
                'isds',
                'test',
                null,
            );
            self::fail('Jiný kanál musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_channel_unsupported', $e->errorCode);
        }
    }
}
