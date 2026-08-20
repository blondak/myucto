<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Submission;

use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\DispatchState;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\GatewayIsdsTransport;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayCredential;
use MyInvoice\Service\Submission\Channel\Isds\IsdsBoxCheck;
use MyInvoice\Service\Submission\Channel\Isds\IsdsChannel;
use MyInvoice\Service\Submission\Channel\Isds\IsdsSendReceipt;
use MyInvoice\Service\Submission\Channel\Isds\IsdsTransport;
use MyInvoice\Service\Submission\Channel\Isds\IsdsTransportTimeout;
use MyInvoice\Service\Submission\Channel\Isds\UnavailableIsdsTransport;
use MyInvoice\Service\Submission\Channel\OutboundSubmission;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Tests\Support\FakeIsdsGatewayRegistrationSource;
use PHPUnit\Framework\TestCase;

/**
 * Adaptér datové schránky nad odesílací branou.
 *
 * Testuje se to, co je u podání drahé zkazit: že se nevědomost nikdy nevydává
 * za výsledek, že prázdný seznam nikdy neznamená „nic nového" a že se
 * nenastavená instalace chová fail-closed.
 *
 * ⚠️ Žádný test se nedotýká sítě ani ostré datové schránky.
 */
final class GatewayIsdsTransportTest extends TestCase
{
    // ───────────────────── 1. úspěch se prokazuje kódem přesně `0000` ─────────────────────

    public function testReceiptExistsOnlyForStatusZeroZeroZeroZero(): void
    {
        $receipt = IsdsSendReceipt::accepted('DM-9000', '0000');

        self::assertSame('DM-9000', $receipt->messageId);
        self::assertSame('0000', $receipt->statusCode);
    }

    /**
     * `isOk()` auditované knihovny bere jako úspěch každý kód začínající `00`,
     * takže by prošlo i `0099`. Typ to musí odmítnout, ne zmírnit.
     */
    public function testStatusOutsideZeroZeroZeroZeroCannotBecomeAReceipt(): void
    {
        foreach (['0099', '00', '2305', '', '0000 '] as $status) {
            try {
                IsdsSendReceipt::accepted('DM-9000', $status);
                self::fail('Stav ' . $status . ' se nesmí prohlásit za přijetí.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCredentialTreatsOnlyZeroZeroZeroZeroAsDispatched(): void
    {
        self::assertTrue($this->credential('0000', 'DM-9000')->isDispatched());
        self::assertFalse($this->credential('0099', 'DM-9000')->isDispatched());
        // Stav v pořádku, ale bez dmID není co považovat za odeslané.
        self::assertFalse($this->credential('0000', null)->isDispatched());
        self::assertTrue($this->credential('2305', null)->isRejectedByUser());
    }

    // ───────────────────── 2. `\Error` po odeslání = nevědomost, ne selhání ─────────────────────

    /**
     * Neúplná odpověď shodí přístup k neinicializované typované property. Je to
     * `\Error` mimo hierarchii výjimek knihovny a přijde až POTÉ, co zpráva
     * mohla odejít — takže se z něj NIKDY nesmí stát `failed`. Kdyby ano,
     * uživatel by podal podruhé a úřad by dostal duplicitu.
     */
    public function testErrorAfterSendingBecomesUncertainNeverFailed(): void
    {
        $channel = new IsdsChannel(new class implements IsdsTransport {
            public function checkRecipientBox(ChannelContext $context, string $boxId): IsdsBoxCheck
            {
                return IsdsBoxCheck::usable($boxId);
            }

            public function createMessage(
                ChannelContext $context,
                string $recipientBoxId,
                string $subject,
                string $senderIdent,
                array $files,
            ): IsdsSendReceipt {
                throw new \Error('Typed property dmStatus must not be accessed before initialization');
            }

            public function findSentBySenderIdent(ChannelContext $context, string $senderIdent): ?string
            {
                return null;
            }

            public function messageState(ChannelContext $context, string $messageId): array
            {
                return ['state' => 'DELIVERED', 'delivered_at' => null, 'accepted_at' => null];
            }

            public function listReceived(ChannelContext $context): array
            {
                return [];
            }

            public function downloadMessage(ChannelContext $context, string $messageId): string
            {
                return '';
            }

            public function downloadDeliveryReceipt(ChannelContext $context, string $messageId): ?string
            {
                return null;
            }
        });

        $result = $channel->send($this->submission(), $this->context());

        self::assertSame(DispatchState::SendUncertain, $result->state);
        self::assertNull($result->externalMessageId);
    }

    public function testTimeoutFromTransportIsUncertainToo(): void
    {
        $channel = new IsdsChannel($this->timeoutTransport());

        $result = $channel->send($this->submission(), $this->context());

        self::assertSame(DispatchState::SendUncertain, $result->state);
    }

    // ───────────────────── 3. brána neodesílá sama — a řekne to ─────────────────────

    /**
     * Prokazatelné odmítnutí (nic neodešlo), ne nevědomost: opakovat správnou
     * cestou je bezpečné. Kdyby to byl {@see IsdsTransportTimeout}, fronta by
     * podání zbytečně zamkla na `send_uncertain`.
     */
    public function testCreateMessageRefusesAndPointsAtTheInteractiveFlow(): void
    {
        $source = new FakeIsdsGatewayRegistrationSource();
        $transport = new GatewayIsdsTransport($source);

        try {
            $transport->createMessage($this->context(), 'abcdefg', 'DPH', 'REF-1', []);
            self::fail('Brána nesmí předstírat synchronní odeslání.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_gateway_dispatch_is_interactive', $e->errorCode);
        }

        // Registrace se opravdu načetla — fail-closed diagnostika, ne jen text.
        self::assertSame(['test'], $source->loadedEnvironments);
    }

    /** Vypnutá nebo rozbitá registrace musí mít svou vlastní příčinu, ne obecnou. */
    public function testCreateMessageSurfacesTheConfigurationErrorFirst(): void
    {
        $source = new FakeIsdsGatewayRegistrationSource();
        $source->loadFailure = new SubmissionChannelException(
            'isds_gateway_certificate_missing',
            'Registrace odesílací brány nemá uložený certifikát.',
            503,
        );

        $this->expectException(SubmissionChannelException::class);
        $this->expectExceptionMessage('nemá uložený certifikát');
        (new GatewayIsdsTransport($source))->createMessage($this->context(), 'abcdefg', 'DPH', 'REF-1', []);
    }

    /** Odeslání přes IsdsChannel skončí `failed`, nikdy `sent`. */
    public function testChannelOverTheGatewayNeverReportsSent(): void
    {
        $result = (new IsdsChannel(new GatewayIsdsTransport(new FakeIsdsGatewayRegistrationSource())))
            ->send($this->submission(), $this->context());

        self::assertSame(DispatchState::Failed, $result->state);
        self::assertSame('isds_gateway_dispatch_is_interactive', $result->errorCode);
    }

    // ───────────────────── 4. čtecí metody: překážka, nikdy prázdný výsledek ─────────────────────

    /**
     * ⚠️ Nejdůležitější test v souboru. Prázdné pole by tvrdilo „ve schránce nic
     * nového není" a `null` u dohledání „taková zpráva tam není". Obojí nevíme.
     */
    public function testEveryReadingMethodRefusesInsteadOfAnsweringEmpty(): void
    {
        $transport = new GatewayIsdsTransport(new FakeIsdsGatewayRegistrationSource());
        $context = $this->context();

        $calls = [
            'listReceived' => static fn () => $transport->listReceived($context),
            'downloadMessage' => static fn () => $transport->downloadMessage($context, 'DM-9000'),
            'downloadDeliveryReceipt' => static fn () => $transport->downloadDeliveryReceipt($context, 'DM-9000'),
            'messageState' => static fn () => $transport->messageState($context, 'DM-9000'),
            'findSentBySenderIdent' => static fn () => $transport->findSentBySenderIdent($context, 'REF-1'),
        ];

        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail($name . '() nesmí vrátit výsledek — brána do schránky nevidí.');
            } catch (SubmissionChannelException $e) {
                self::assertSame('isds_gateway_read_unsupported', $e->errorCode, $name);
                self::assertSame(503, $e->httpStatus, $name);
            }
        }
    }

    /**
     * Rekonciliace po nejistém konci: dohledání podle `dmSenderIdent` po bráně
     * nejde, takže se `probe()` nesmí tvářit, že zpráva neodešla.
     */
    public function testProbeAfterTimeoutStaysInconclusive(): void
    {
        $probe = (new IsdsChannel(new GatewayIsdsTransport(new FakeIsdsGatewayRegistrationSource())))
            ->probe('REF-1', $this->context());

        self::assertFalse($probe->resolved);
        self::assertNull($probe->externalMessageId);
        self::assertNotNull($probe->reason);
    }

    /** Ověření schránky je povinný krok — nevědomost se nesmí vydávat za „je v pořádku". */
    public function testRecipientCheckRefusesInsteadOfClaimingTheBoxIsFine(): void
    {
        $this->expectException(SubmissionChannelException::class);
        $this->expectExceptionMessage('neumí zeptat');
        (new GatewayIsdsTransport(new FakeIsdsGatewayRegistrationSource()))
            ->checkRecipientBox($this->context(), 'abcdefg');
    }

    // ───────────────────── 5. fail-closed rozcestník ─────────────────────

    public function testConfiguredOnlyWhenSomeEnvironmentIsReady(): void
    {
        $source = new FakeIsdsGatewayRegistrationSource();
        $source->ready = ['production' => false, 'test' => false];
        self::assertFalse(GatewayIsdsTransport::isConfigured($source));

        $source->ready = ['production' => false, 'test' => true];
        self::assertTrue(GatewayIsdsTransport::isConfigured($source));
    }

    /** Chybějící tabulka (migrace neproběhly) nesmí shodit kontejner ani otevřít cestu. */
    public function testBrokenRegistrationStorageFallsBackToUnavailable(): void
    {
        $source = new FakeIsdsGatewayRegistrationSource();
        $source->readyFailure = new \RuntimeException('Table isds_gateway_registrations does not exist');

        self::assertFalse(GatewayIsdsTransport::isConfigured($source));
    }

    /** Bez konfigurace platí `UnavailableIsdsTransport` — a ten taky nikdy nevrací prázdno. */
    public function testUnavailableFallbackRefusesEverythingToo(): void
    {
        $transport = new UnavailableIsdsTransport();
        $context = $this->context();

        $this->expectException(SubmissionChannelException::class);
        $transport->listReceived($context);
    }

    // ───────────────────── pomocné ─────────────────────

    private function credential(?string $statusCode, ?string $dmId): IsdsGatewayCredential
    {
        return new IsdsGatewayCredential(
            timeLimitedId: \MyInvoice\Service\Submission\Channel\SensitiveValue::fromProducer(
                static fn (): string => 'T01-tajne',
            ),
            appToken: null,
            conceptDmId: $dmId,
            conceptStatusCode: $statusCode,
            conceptStatusMessage: null,
        );
    }

    private function timeoutTransport(): IsdsTransport
    {
        return new class implements IsdsTransport {
            public function checkRecipientBox(ChannelContext $context, string $boxId): IsdsBoxCheck
            {
                return IsdsBoxCheck::usable($boxId);
            }

            public function createMessage(
                ChannelContext $context,
                string $recipientBoxId,
                string $subject,
                string $senderIdent,
                array $files,
            ): IsdsSendReceipt {
                throw new IsdsTransportTimeout('isds_timeout', 'Spojení se přerušilo.');
            }

            public function findSentBySenderIdent(ChannelContext $context, string $senderIdent): ?string
            {
                return null;
            }

            public function messageState(ChannelContext $context, string $messageId): array
            {
                return ['state' => 'DELIVERED', 'delivered_at' => null, 'accepted_at' => null];
            }

            public function listReceived(ChannelContext $context): array
            {
                return [];
            }

            public function downloadMessage(ChannelContext $context, string $messageId): string
            {
                return '';
            }

            public function downloadDeliveryReceipt(ChannelContext $context, string $messageId): ?string
            {
                return null;
            }
        };
    }

    private function context(): ChannelContext
    {
        return new ChannelContext(1, 'test', new ChannelCredentials('abcdefg', 'certificate'));
    }

    private function submission(): OutboundSubmission
    {
        return new OutboundSubmission(
            outboxId: 1,
            supplierId: 1,
            environment: 'test',
            agendaCode: 'dph',
            subject: 'Přiznání k DPH',
            recipientBoxId: 'abcdefg',
            artifactFilename: 'dph.xml',
            artifactMimeType: 'application/xml',
            artifactBytes: '<x/>',
            artifactSha256: hash('sha256', '<x/>'),
            correlationReference: 'REF-1',
        );
    }
}
