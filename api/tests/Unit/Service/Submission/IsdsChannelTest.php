<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Service\Submission\Channel\AcceptanceState;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\ChannelEvidenceStrength;
use MyInvoice\Service\Submission\Channel\DispatchState;
use MyInvoice\Service\Submission\Channel\Isds\IsdsChannel;
use MyInvoice\Service\Submission\Channel\OutboundSubmission;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Tests\Support\FakeIsdsTransport;
use PHPUnit\Framework\TestCase;

/**
 * Kontrakt kanálu datové schránky.
 *
 * Žádný z těchhle testů nesahá na síť — {@see FakeIsdsTransport} je čistě
 * paměťová náhrada. Skutečné odeslání dělá výhradně člověk.
 */
final class IsdsChannelTest extends TestCase
{
    private FakeIsdsTransport $transport;
    private IsdsChannel $channel;

    protected function setUp(): void
    {
        $this->transport = new FakeIsdsTransport();
        $this->channel = new IsdsChannel($this->transport);
    }

    public function testChannelCanNeverProveAcceptance(): void
    {
        // Datová schránka vrací doručenku, ne protokol o zpracování. Kdyby se
        // tahle hodnota kdy změnila, mohl by kanál posunout podání na
        // „zpracováno úřadem" — a to je přesně ta záměna, které se bráníme.
        self::assertSame(ChannelEvidenceStrength::DeliveryOnly, $this->channel->evidenceStrength());
        self::assertFalse($this->channel->evidenceStrength()->canProveAcceptance());
    }

    public function testSuccessfulSendReturnsMessageIdAndStampsSenderIdent(): void
    {
        $result = $this->channel->send($this->submission(), $this->context());

        self::assertSame(DispatchState::Sent, $result->state);
        self::assertSame('DM-1000', $result->externalMessageId);
        // Bez razítka v dmSenderIdent by po timeoutu nešlo dohledat, jestli
        // zpráva odešla.
        self::assertSame('DPHDP3-20260815-ABCDEF', $this->transport->sentMessages[0]['sender_ident']);
    }

    public function testTimeoutBecomesUncertainNeverFailed(): void
    {
        $this->transport->sendBehaviour = 'timeout';

        $result = $this->channel->send($this->submission(), $this->context());

        // „failed" by svedlo k odeslání znovu a úřad by dostal duplicitu.
        self::assertSame(DispatchState::SendUncertain, $result->state);
        self::assertNull($result->externalMessageId);
    }

    public function testFatalErrorAfterPossibleSendBecomesUncertain(): void
    {
        // Neúplná odpověď knihovny hodí `\Error` MIMO hierarchii jejích výjimek,
        // a to až poté, co zpráva mohla odejít. Spolknout to jako selhání by
        // znamenalo duplicitní podání.
        $this->transport->sendBehaviour = 'fatal';

        $result = $this->channel->send($this->submission(), $this->context());

        self::assertSame(DispatchState::SendUncertain, $result->state);
        self::assertSame('isds_unexpected_error', $result->errorCode);
    }

    public function testExplicitRefusalIsFailedNotUncertain(): void
    {
        $this->transport->sendBehaviour = 'refuse';

        $result = $this->channel->send($this->submission(), $this->context());

        // Prokazatelné odmítnutí — nic neodešlo, opakovat je bezpečné.
        self::assertSame(DispatchState::Failed, $result->state);
    }

    public function testSenderIdentLongerThanFiftyCharactersIsRefusedBeforeSending(): void
    {
        $submission = $this->submission(str_repeat('X', 51));

        $result = $this->channel->send($submission, $this->context());

        self::assertSame(DispatchState::Failed, $result->state);
        self::assertSame('sender_ident_too_long', $result->errorCode);
        self::assertSame([], $this->transport->sentMessages, 'Zpráva se nesmí odeslat oříznutá.');
    }

    public function testProbeFindsMessageThatActuallyLeftDespiteTimeout(): void
    {
        $this->transport->sendBehaviour = 'timeout_but_delivered';
        $this->channel->send($this->submission(), $this->context());

        $probe = $this->channel->probe('DPHDP3-20260815-ABCDEF', $this->context());

        self::assertTrue($probe->resolved);
        self::assertSame('DM-1000', $probe->externalMessageId);
    }

    public function testProbeReportsNotSentWhenNothingLeft(): void
    {
        $this->transport->sendBehaviour = 'timeout';
        $this->channel->send($this->submission(), $this->context());

        $probe = $this->channel->probe('DPHDP3-20260815-ABCDEF', $this->context());

        self::assertTrue($probe->resolved);
        self::assertNull($probe->externalMessageId);
    }

    public function testProbeStaysInconclusiveWhenMailboxIsUnreachable(): void
    {
        $this->transport->probeBehaviour = 'fail';

        $probe = $this->channel->probe('DPHDP3-20260815-ABCDEF', $this->context());

        // Nedovolali jsme se → nevědomost trvá. „Neodešlo" by svedlo
        // k odeslání duplicity.
        self::assertFalse($probe->resolved);
        self::assertNull($probe->externalMessageId);
    }

    public function testDeliveredStatusNeverCarriesAcceptance(): void
    {
        $this->transport->states['DM-1000'] = [
            'state' => 'DELIVERED',
            'delivered_at' => '2026-08-15 10:00:00',
            // Jméno „acceptance" v ISDS znamená DORUČENÍ, ne přijetí úřadem.
            'accepted_at' => '2026-08-15 10:00:00',
        ];

        $status = $this->channel->fetchStatus('DM-1000', $this->context());

        self::assertSame(DispatchState::Delivered, $status->dispatch);
        self::assertSame(AcceptanceState::Unknown, $status->acceptance);
        self::assertNull($status->evidence);
    }

    public function testStatusBeforeDeliveryStaysSent(): void
    {
        $this->transport->states['DM-1000'] = ['state' => 'POSTED', 'delivered_at' => null, 'accepted_at' => null];

        $status = $this->channel->fetchStatus('DM-1000', $this->context());

        self::assertSame(DispatchState::Sent, $status->dispatch);
        self::assertSame(AcceptanceState::Unknown, $status->acceptance);
    }

    public function testLateDeliveryIsAppliedWhenReceiptFinallyArrives(): void
    {
        $this->transport->states['DM-1000'] = ['state' => 'POSTED', 'delivered_at' => null, 'accepted_at' => null];
        self::assertSame(DispatchState::Sent, $this->channel->fetchStatus('DM-1000', $this->context())->dispatch);

        $this->transport->states['DM-1000'] = [
            'state' => 'DELIVERED',
            'delivered_at' => '2026-08-20 08:30:00',
            'accepted_at' => null,
        ];

        $status = $this->channel->fetchStatus('DM-1000', $this->context());
        self::assertSame(DispatchState::Delivered, $status->dispatch);
        self::assertSame('2026-08-20 08:30:00', $status->deliveredAt?->format('Y-m-d H:i:s'));
        self::assertSame(AcceptanceState::Unknown, $status->acceptance);
    }

    public function testInboxFailureThrowsInsteadOfLookingEmpty(): void
    {
        $this->transport->inboxBehaviour = 'fail';

        // Kdyby tohle vrátilo prázdný seznam, „schránka je prázdná" a „na
        // schránku se nedovoláme" by z aplikace vypadaly stejně.
        $this->expectException(SubmissionChannelException::class);
        $this->channel->listNew($this->context());
    }

    public function testEmptyInboxIsAValidAnswer(): void
    {
        $this->transport->inboxMessages = [];

        $listing = $this->channel->listNew($this->context());

        self::assertTrue($listing->isEmpty());
        self::assertSame(0, $listing->count());
    }

    public function testRecipientVerificationIsDelegatedToIsds(): void
    {
        $this->transport->boxBehaviour = 'unusable';

        $verification = $this->channel->verifyRecipient('abcdefg', $this->context());

        self::assertNotNull($verification);
        self::assertFalse($verification['usable']);
    }

    private function submission(string $correlation = 'DPHDP3-20260815-ABCDEF'): OutboundSubmission
    {
        return new OutboundSubmission(
            outboxId: 1,
            supplierId: 1,
            environment: 'test',
            agendaCode: 'DPHDP3',
            subject: 'Přiznání k DPH',
            recipientBoxId: 'abcdefg',
            artifactFilename: 'dphdp3.xml',
            artifactMimeType: 'application/xml',
            artifactBytes: '<x/>',
            artifactSha256: str_repeat('a', 64),
            correlationReference: $correlation,
        );
    }

    private function context(): ChannelContext
    {
        return new ChannelContext(1, 'test', new ChannelCredentials('zzzzzzz', 'certificate'));
    }
}
