<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzEnvelopeSignerInterface;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzGovTalkEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use PHPUnit\Framework\TestCase;

final class JmhzGovTalkEnvelopeTest extends TestCase
{
    private function software(): JmhzSoftwareIdentification
    {
        return new JmhzSoftwareIdentification('MyUcto', '1.0');
    }

    private function envelope(): JmhzGovTalkEnvelope
    {
        return new JmhzGovTalkEnvelope(JmhzTransportSample::shape());
    }

    public function testUndeclaredEnvelopeShapeIsRefusedInsteadOfGuessed(): void
    {
        $this->expectException(JmhzTransportException::class);
        $this->expectExceptionMessage('není v podkladech ČSSZ doložený');

        (new JmhzGovTalkEnvelope())->build(
            JmhzTransportSample::payload(),
            JmhzTransportSample::VARIABLE_SYMBOL,
            'CSSZ_JMHZ',
            'test',
            $this->software(),
        );
    }

    public function testEnvelopeCarriesTheDocumentedGovTalkSkeleton(): void
    {
        $document = $this->envelope()->build(
            JmhzTransportSample::payload(),
            JmhzTransportSample::VARIABLE_SYMBOL,
            'CSSZ_JMHZ',
            'test',
            $this->software(),
        );

        $xml = $document->unsignedXml;
        self::assertStringContainsString(
            '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">',
            $xml,
        );
        self::assertStringContainsString('<EnvelopeVersion>2.0</EnvelopeVersion>', $xml);
        self::assertStringContainsString('<Class>CSSZ_JMHZ</Class>', $xml);
        self::assertStringContainsString('<Qualifier>request</Qualifier>', $xml);
        self::assertStringContainsString('<Function>submit</Function>', $xml);
        self::assertStringContainsString(
            '<Key Type="VS">' . JmhzTransportSample::VARIABLE_SYMBOL . '</Key>',
            $xml,
        );
        self::assertStringContainsString(
            '<Message xmlns="http://www.cssz.cz/XMLSchema/envelope" version="1.2" eType="request">',
            $xml,
        );
        // Prázdná hlavička ČSSZ obálky je slot pro podpis.
        self::assertStringContainsString('<Header/><Body>', $xml);
        self::assertSame('test', $document->environment);
        self::assertSame(JmhzTransportSample::VARIABLE_SYMBOL, $document->variableSymbol);
    }

    public function testEnvelopeIsByteStableForTheSameInput(): void
    {
        $envelope = $this->envelope();
        $arguments = [
            JmhzTransportSample::payload(),
            JmhzTransportSample::VARIABLE_SYMBOL,
            'CSSZ_JMHZ',
            'test',
            $this->software(),
        ];

        $first = $envelope->build(...$arguments);
        $second = $envelope->build(...$arguments);

        self::assertSame($first->unsignedXml, $second->unsignedXml);
        self::assertSame($first->sha256(), $second->sha256());
    }

    public function testEnvelopeWithoutSignerIsNotSendable(): void
    {
        $document = $this->envelope()->build(
            JmhzTransportSample::payload(),
            JmhzTransportSample::VARIABLE_SYMBOL,
            'CSSZ_JMHZ',
            'test',
            $this->software(),
        );

        try {
            $document->sendableXml(null);
            self::fail('Nepodepsaná obálka nesmí být odesílatelná.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_govtalk_signer_missing', $e->errorCode);
        }
    }

    public function testSignerThatBreaksTheEnvelopeIsRejected(): void
    {
        $document = $this->envelope()->build(
            JmhzTransportSample::payload(),
            JmhzTransportSample::VARIABLE_SYMBOL,
            'CSSZ_JMHZ',
            'test',
            $this->software(),
        );
        $broken = new class implements JmhzEnvelopeSignerInterface {
            public function sign(string $envelopeXml): string
            {
                return '<Neco/>';
            }
        };

        try {
            $document->sendableXml($broken);
            self::fail('Rozbitá obálka nesmí projít.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_govtalk_signature_broke_envelope', $e->errorCode);
        }
    }

    public function testSignedEnvelopeIsHandedOverUnchanged(): void
    {
        $document = $this->envelope()->build(
            JmhzTransportSample::payload(),
            JmhzTransportSample::VARIABLE_SYMBOL,
            'CSSZ_JMHZ',
            'test',
            $this->software(),
        );
        $signer = new class implements JmhzEnvelopeSignerInterface {
            public function sign(string $envelopeXml): string
            {
                return str_replace('<Header/>', '<Header>podpis</Header>', $envelopeXml);
            }
        };

        $signed = $document->sendableXml($signer);

        self::assertStringContainsString('<Header>podpis</Header>', $signed);
    }

    public function testVariableSymbolMustMatchTheSubmissionHeader(): void
    {
        try {
            $this->envelope()->build(
                JmhzTransportSample::payload('9990000002'),
                JmhzTransportSample::VARIABLE_SYMBOL,
                'CSSZ_JMHZ',
                'test',
                $this->software(),
            );
            self::fail('Rozpor variabilních symbolů musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_govtalk_variable_symbol_mismatch', $e->errorCode);
        }
    }

    public function testSoftwareIdentificationMustMatchTheVendorElement(): void
    {
        try {
            $this->envelope()->build(
                JmhzTransportSample::payload(
                    JmhzTransportSample::VARIABLE_SYMBOL,
                    'JinySoftware',
                ),
                JmhzTransportSample::VARIABLE_SYMBOL,
                'CSSZ_JMHZ',
                'test',
                $this->software(),
            );
            self::fail('Rozpor identifikace software musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_govtalk_vendor_mismatch', $e->errorCode);
        }
    }

    public function testUndocumentedClassAndMalformedSymbolAreRefused(): void
    {
        $failures = [];
        foreach ([
            ['CSSZ_NECO', JmhzTransportSample::VARIABLE_SYMBOL],
            ['CSSZ_JMHZ', '999'],
        ] as [$class, $symbol]) {
            try {
                $this->envelope()->build(
                    JmhzTransportSample::payload(),
                    $symbol,
                    $class,
                    'test',
                    $this->software(),
                );
            } catch (JmhzTransportException $e) {
                $failures[] = $e->errorCode;
            }
        }

        self::assertSame(
            ['jmhz_govtalk_class_unknown', 'jmhz_govtalk_variable_symbol_invalid'],
            $failures,
        );
    }

    public function testPollRequestCarriesCorrelationIdAndPollQualifier(): void
    {
        $xml = $this->envelope()->pollRequest(
            'CID0000000001',
            JmhzTransportSample::VARIABLE_SYMBOL,
            'CSSZ_JMHZ',
        );

        self::assertStringContainsString('<Qualifier>poll</Qualifier>', $xml);
        self::assertStringContainsString(
            '<CorrelationID>CID0000000001</CorrelationID>',
            $xml,
        );
    }
}
