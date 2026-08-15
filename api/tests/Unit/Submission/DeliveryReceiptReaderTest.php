<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Submission;

use MyInvoice\Service\Document\DocumentException;
use MyInvoice\Service\Document\ZfoExtractor;
use MyInvoice\Service\Submission\DeliveryReceiptReader;
use MyInvoice\Tests\Support\SyntheticZfoBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Čtení doručenky ze ZFO — bez databáze a bez sítě.
 *
 * Těžiště je na dvou věcech: že se nezamění DODÁNÍ s DORUČENÍM (ISDS je
 * pojmenoval tak, že to svádí), a že každý vadný soubor selže s vlastním
 * vysvětlením místo jednoho univerzálního „nepodařilo se".
 */
#[Group('unit')]
final class DeliveryReceiptReaderTest extends TestCase
{
    private DeliveryReceiptReader $reader;

    protected function setUp(): void
    {
        $this->reader = new DeliveryReceiptReader(new ZfoExtractor());
    }

    public function testReadsIdentifiersNeededForMatching(): void
    {
        $receipt = $this->reader->read(SyntheticZfoBuilder::receipt([
            'message_id' => '9900042',
            'sender_ident' => 'DPHDP3-20260801-ABCDEF012345',
            'annotation' => 'DPH za červenec 2026',
        ]));

        self::assertSame('9900042', $receipt->messageId);
        self::assertSame('test111', $receipt->senderBoxId);
        self::assertSame('test999', $receipt->recipientBoxId);
        self::assertSame('DPHDP3-20260801-ABCDEF012345', $receipt->senderIdent);
        self::assertSame('DPH za červenec 2026', $receipt->subject);
        self::assertTrue($receipt->hasSenderIdent());
        self::assertSame(hash('sha256', SyntheticZfoBuilder::receipt([
            'message_id' => '9900042',
            'sender_ident' => 'DPHDP3-20260801-ABCDEF012345',
            'annotation' => 'DPH za červenec 2026',
        ])), $receipt->rawSha256);
    }

    /**
     * `dmAcceptanceTime` se jmenuje „acceptance", ale znamená DORUČENÍ, ne
     * přijetí úřadem. Kdo si to splete, vyrobí podání, které se tváří jako
     * vyřízené.
     */
    public function testDeliveryTimeIsDispatchAndAcceptanceTimeIsDelivery(): void
    {
        $receipt = $this->reader->read(SyntheticZfoBuilder::receipt([
            'delivery_time' => '2026-08-01T09:15:00.000+02:00',
            'acceptance_time' => '2026-08-01T11:20:00.000+02:00',
        ]));

        self::assertSame('2026-08-01 09:15:00', $receipt->sentAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-01 11:20:00', $receipt->deliveredAt()?->format('Y-m-d H:i:s'));
        self::assertTrue($receipt->deliveredAt() >= $receipt->sentAt(), 'Doručení nesmí předcházet dodání.');
    }

    /** Dokud se příjemce nepřihlásil, doručenka nese jen dodání — a to stačí. */
    public function testDeliveredAtFallsBackToDeliveryTime(): void
    {
        $receipt = $this->reader->read(SyntheticZfoBuilder::receipt([
            'acceptance_time' => null,
        ]));

        self::assertSame('2026-08-01 09:15:00', $receipt->deliveredAt()?->format('Y-m-d H:i:s'));
    }

    public function testReceiptWithoutOurReferenceIsRecognisedAsSuch(): void
    {
        $receipt = $this->reader->read(SyntheticZfoBuilder::receipt(['sender_ident' => null]));

        self::assertFalse($receipt->hasSenderIdent());
        self::assertNull($receipt->senderIdent);
    }

    /** Nesmyslné ID schránky se zahodí — polovičatý údaj je horší než žádný. */
    public function testMalformedBoxIdIsDiscarded(): void
    {
        $receipt = $this->reader->read(SyntheticZfoBuilder::receipt([
            'recipient_box_id' => 'nesmysl-navic',
        ]));

        self::assertNull($receipt->recipientBoxId);
    }

    public function testEmptyFileHasItsOwnError(): void
    {
        try {
            $this->reader->read('');
            self::fail('Prázdný soubor není doručenka.');
        } catch (DocumentException $e) {
            self::assertSame('receipt_empty', $e->errorCode);
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testPdfInsteadOfZfoHasItsOwnError(): void
    {
        try {
            $this->reader->read(SyntheticZfoBuilder::notAZfo());
            self::fail('PDF není doručenka.');
        } catch (DocumentException $e) {
            self::assertSame('receipt_not_zfo', $e->errorCode);
            self::assertStringContainsString('.zfo', $e->getMessage());
        }
    }

    public function testCorruptedContentHasItsOwnError(): void
    {
        try {
            $this->reader->read(SyntheticZfoBuilder::corruptedInsideValidEnvelope());
            self::fail('Poškozený obsah se přečíst nedá.');
        } catch (DocumentException $e) {
            self::assertSame('zfo_parse_failed', $e->errorCode);
        }
    }

    public function testMissingMessageIdHasItsOwnError(): void
    {
        try {
            $this->reader->read(SyntheticZfoBuilder::receiptWithoutMessageId());
            self::fail('Bez ID zprávy není co párovat.');
        } catch (DocumentException $e) {
            self::assertSame('receipt_missing_message_id', $e->errorCode);
        }
    }
}
