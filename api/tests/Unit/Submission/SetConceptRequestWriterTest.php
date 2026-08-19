<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Submission;

use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsConceptMessage;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\SetConceptRequestWriter;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use PHPUnit\Framework\TestCase;

/**
 * Požadavek `SetConcept` se validuje proti ORIGINÁLNÍMU `SetConcept.xsd`
 * z Technické přílohy 2 Provozního řádu ISDS.
 *
 * ISDS nám obsah nezvaliduje a chybu bychom zjistili až tím, že podání
 * neprojde — tenhle test je náhrada za validaci, kterou druhá strana nedělá.
 */
final class SetConceptRequestWriterTest extends TestCase
{
    private const SCHEMA = __DIR__ . '/../../../xsd/isds/SetConcept.xsd';

    public function testRequestValidatesAgainstOfficialSchema(): void
    {
        $xml = (new SetConceptRequestWriter())->body($this->message());

        self::assertTrue($this->validate($xml), 'Požadavek SetConcept musí projít oficiálním XSD.');
    }

    /**
     * Jádro celé třídy: povinné-ale-prázdné prvky se musí zapsat jako
     * `xsi:nil="true"`, ne vynechat. Kdyby je někdo v budoucnu „uklidil",
     * tenhle test spadne dřív, než to udělá ostré podání.
     */
    public function testEmptyEnvelopeFieldsAreWrittenAsNilNotOmitted(): void
    {
        $xml = (new SetConceptRequestWriter())->body($this->message());

        self::assertStringContainsString('dmSenderOrgUnit', $xml);
        self::assertMatchesRegularExpression(
            '~<p:dmSenderOrgUnit[^>]*\bxsi:nil="true"~',
            $xml,
            'Prázdný prvek obálky musí být nillable, ne vynechaný.',
        );

        // Kontrolní pokus: bez těch prvků schéma request odmítne. Kdyby ho
        // přijalo, znamenalo by to, že testujeme proti jinému schématu.
        $stripped = preg_replace('~<p:dmSenderOrgUnit[^>]*/>~', '', $xml);
        self::assertIsString($stripped);
        self::assertFalse($this->validate($stripped), 'XSD musí vynechaný povinný prvek odmítnout.');
    }

    public function testAttachmentMetadataAreAttributesAndFirstFileIsMain(): void
    {
        $message = new IsdsConceptMessage('abcdefg', 'Věc', 'JMHZ-20260817-AABB', [
            ['filename' => 'jmhz.xml', 'mime' => 'application/xml', 'bytes' => '<a/>'],
            ['filename' => 'priloha.pdf', 'mime' => 'application/pdf', 'bytes' => '%PDF-1.4'],
        ]);
        $xml = (new SetConceptRequestWriter())->body($message);

        self::assertTrue($this->validate($xml));
        self::assertStringContainsString('dmFileMetaType="main"', $xml);
        self::assertStringContainsString('dmFileMetaType="enclosure"', $xml);
        self::assertStringContainsString('dmFileDescr="jmhz.xml"', $xml);
        self::assertStringContainsString(base64_encode('%PDF-1.4'), $xml);
    }

    /**
     * „Není povoleno uvádět typ zprávy jako komerční, typ zprávy je automaticky
     * označen až v okamžiku odsouhlasení konceptu." (kap. 3.4)
     */
    public function testEnvelopeNeverDeclaresMessageType(): void
    {
        $xml = (new SetConceptRequestWriter())->body($this->message());

        self::assertStringNotContainsString('dmType', $xml);
    }

    public function testSoapEnvelopeWrapsTheSameBody(): void
    {
        $writer = new SetConceptRequestWriter();
        $envelope = $writer->envelope($this->message());

        self::assertStringContainsString(SetConceptRequestWriter::NS_SOAP, $envelope);
        self::assertStringContainsString('SetConcept', $envelope);
        self::assertStringContainsString('dmSenderIdent', $envelope);
    }

    public function testDiacriticsSurviveIntoTheAnnotation(): void
    {
        $message = new IsdsConceptMessage(
            'abcdefg',
            'JMHZ – Jednotné měsíční hlášení zaměstnavatele za 07/2026, VS 1234567890',
            'JMHZ-20260817-AABB',
            [['filename' => 'jmhz.xml', 'mime' => 'application/xml', 'bytes' => '<a/>']],
        );
        $xml = (new SetConceptRequestWriter())->body($message);

        self::assertTrue($this->validate($xml));
        self::assertStringContainsString('měsíční hlášení', $xml);
    }

    public function testOversizedAnnotationIsRefusedBeforeAnyNetworkCall(): void
    {
        $message = new IsdsConceptMessage(
            'abcdefg',
            str_repeat('á', 256),
            'JMHZ-20260817-AABB',
            [['filename' => 'jmhz.xml', 'mime' => 'application/xml', 'bytes' => '<a/>']],
        );

        $this->expectException(SubmissionChannelException::class);
        $this->expectExceptionMessage('255 znaků');
        $message->assertValid();
    }

    public function testInvalidRecipientBoxIsRefused(): void
    {
        $message = new IsdsConceptMessage(
            'toolong',  // 7 znaků, ale ověříme i tvar
            'Věc',
            'JMHZ-20260817-AABB',
            [['filename' => 'jmhz.xml', 'mime' => 'application/xml', 'bytes' => '<a/>']],
        );
        // 'toolong' je platných 7 alfanumerických znaků → projde.
        $message->assertValid();

        $invalid = new IsdsConceptMessage(
            'ab-defg',
            'Věc',
            'JMHZ-20260817-AABB',
            [['filename' => 'jmhz.xml', 'mime' => 'application/xml', 'bytes' => '<a/>']],
        );
        $this->expectException(SubmissionChannelException::class);
        $invalid->assertValid();
    }

    private function message(): IsdsConceptMessage
    {
        return new IsdsConceptMessage(
            'abcdefg',
            'JMHZ - Jednotné měsíční hlášení zaměstnavatele za 07/2026, VS 1234567890',
            'JMHZ-20260817-AABBCCDDEEFF',
            [['filename' => 'JMHZ_1234567890_07-2026.xml', 'mime' => 'application/xml', 'bytes' => '<Podani/>']],
        );
    }

    private function validate(string $xml): bool
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if ($document->loadXML($xml) === false) {
                return false;
            }
            $valid = $document->schemaValidate(self::SCHEMA);
            libxml_clear_errors();

            return $valid;
        } finally {
            libxml_use_internal_errors($previous);
        }
    }
}
