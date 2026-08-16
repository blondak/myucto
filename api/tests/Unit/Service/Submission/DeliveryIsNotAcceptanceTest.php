<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Service\Submission\Channel\AcceptanceEvidence;
use MyInvoice\Service\Submission\Channel\AcceptanceState;
use MyInvoice\Service\Submission\Channel\ChannelEvidenceStrength;
use MyInvoice\Service\Submission\Channel\ChannelStatus;
use MyInvoice\Service\Submission\Channel\DispatchState;
use PHPUnit\Framework\TestCase;

/**
 * Nejdůležitější tvrzení celého modulu: **„doručeno" a „zpracováno" jsou dva
 * různé stavy a nesmí se slít.**
 *
 * Projekt už jednou doplatil na záměnu „odesláno = přijato". Datová schránka
 * vrací doručenku — důkaz, že zpráva DORAZILA do schránky úřadu. O tom, jestli
 * ji úřad zpracoval a přijal, neříká nic; § 73 odst. 3 DŘ váže automatické
 * potvrzení s podacím číslem výhradně na podání na technické zařízení správce
 * daně. Chyby přijdou až po dnech jako výzva podle § 74 DŘ.
 *
 * Ochrana stojí na čtyřech nezávislých vrstvách a tenhle test hlídá tři z nich
 * (čtvrtou, databázovou, hlídá `SubmissionOutboxInvariantsTest`).
 */
final class DeliveryIsNotAcceptanceTest extends TestCase
{
    /**
     * Vrstva 1 — SLOVNÍK. Ve výčtu důkazů o vyřízení není a nikdy nesmí být
     * položka pro doručenku. Co nejde pojmenovat, nejde zapsat.
     */
    public function testEvidenceVocabularyHasNoWordForDeliveryReceipt(): void
    {
        $values = array_map(
            static fn (AcceptanceEvidence $case): string => $case->value,
            AcceptanceEvidence::cases(),
        );

        self::assertSame(
            ['epo_protocol', 'agency_protocol_message', 'manual_confirmation'],
            $values,
            'Do výčtu důkazů o PŘIJETÍ nesmí přibýt doručenka — je to důkaz o doručení, ne o zpracování.',
        );

        foreach ($values as $value) {
            self::assertStringNotContainsString('delivery', $value);
            self::assertStringNotContainsString('receipt', $value);
            self::assertStringNotContainsString('dorucen', $value);
        }
    }

    /**
     * Vrstva 2 — TVAR. Stav nelze sestrojit tak, aby tvrdil vyřízení bez důkazu.
     */
    public function testAcceptanceWithoutEvidenceCannotBeConstructed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChannelStatus(DispatchState::Delivered, AcceptanceState::Accepted, null, new \DateTimeImmutable());
    }

    public function testUnknownAcceptanceCannotCarryEvidence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChannelStatus(
            DispatchState::Delivered,
            AcceptanceState::Unknown,
            AcceptanceEvidence::EpoProtocol,
            new \DateTimeImmutable(),
        );
    }

    /**
     * Nejběžnější odpověď datové schránky má vlastní továrnu právě proto, aby
     * bylo na jednom místě vidět, že osu vyřízení nechává na `Unknown`.
     */
    public function testDeliveredOnlyLeavesAcceptanceUnknown(): void
    {
        $status = ChannelStatus::deliveredOnly(new \DateTimeImmutable('2026-08-15 09:00:00'));

        self::assertSame(DispatchState::Delivered, $status->dispatch);
        self::assertSame(AcceptanceState::Unknown, $status->acceptance);
        self::assertNull($status->evidence);
    }

    public function testDeliveredDispatchRequiresDeliveryTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChannelStatus(DispatchState::Delivered);
    }

    /**
     * Vrstva 3 — OPRÁVNĚNÍ. Jen kanál se strukturovaným protokolem smí osu
     * vyřízení posunout. Tuhle podmínku vyhodnocuje `SubmissionOutboxService`.
     */
    public function testOnlyProtocolChannelMayProveAcceptance(): void
    {
        self::assertTrue(ChannelEvidenceStrength::ProcessingProtocol->canProveAcceptance());
        self::assertFalse(ChannelEvidenceStrength::DeliveryOnly->canProveAcceptance());
    }

    /** „Doručeno" je na ose dopravy koncové — samo od sebe nikam nepokročí. */
    public function testDeliveredIsTerminalOnTheTransportAxis(): void
    {
        self::assertTrue(DispatchState::Delivered->isTerminal());
        self::assertTrue(DispatchState::Delivered->hasLeft());
        self::assertFalse(DispatchState::SendUncertain->hasLeft());
        self::assertFalse(DispatchState::SendUncertain->isTerminal());
    }
}
