<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Repository\Payroll\PayrollSigningProfileRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzGovTalkEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzReceiptVerifier;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSubmissionStatus;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzVrepClient;
use MyInvoice\Service\Payroll\Submission\PayrollDispatchGate;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Signing\PersonalCertificateVaultService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

// Oba repozitáře ledgeru jsou `final`. Mockují se týmž mechanismem jako zbytek
// sady (viz tests/bootstrap.php), jen se cesty přidávají tady — allowPaths()
// seznam doplňuje, takže kvůli jedné testovací třídě nemusí růst globální
// seznam v bootstrapu.
/**
 * Odesílací cesta JMHZ na VREP. Testuje se proti falešnému VREP (Guzzle
 * MockHandler) a efemérnímu certifikátu vyrobenému v testu — skutečný
 * podpisový klíč se do sady nikdy nedostane.
 *
 * Vzorky odpovědí jsou doslovné: potvrzení převzetí je zkrácená odpověď
 * testovacího VREP na reálně odeslané podání, protokol je vzorek z
 * `JmhzTransportSample`.
 */
final class JmhzDispatchServiceTest extends TestCase
{
    private const SUPPLIER = 11;
    private const SUBMISSION = 42;
    private const ATTEMPT = 7;
    private const CORRELATION = 'CCCC9999DDDD0000EEEE1111FFFF2222';

    /** @var list<array<string,mixed>> */
    private array $history = [];

    /** @var list<string> */
    private array $log = [];

    /** Argumenty, se kterými ledger dostal zápis neúspěchu. */
    private ?array $failure = null;

    protected function setUp(): void
    {
        if (!function_exists('openssl_cms_sign') || !function_exists('openssl_cms_encrypt')) {
            self::markTestSkipped('Server nepodporuje CMS.');
        }
        $this->history = [];
        $this->log = [];
        $this->failure = null;
    }

    /**
     * Ledger se musí založit DŘÍV, než obálka opustí proces: pád mezi odesláním
     * a zápisem by u ČSSZ nechal podání, o kterém aplikace neví, a druhý pokus
     * by narazil na duplicitu bez vysvětlení. Potvrzení převzetí přitom není
     * přijetí — `isSettled()` proto zůstává false.
     */
    public function testSendOpensTheLedgerBeforeTheEnvelopeLeavesAndRecordsTheCorrelation(): void
    {
        $attempts = $this->attempts();
        $attempts->method('nextAttemptNo')->willReturn(1);
        $attempts->expects(self::once())->method('open')->with(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            JmhzDispatchService::CHANNEL,
            1,
            'jmhz-2026-04-11',
            hash('sha256', JmhzTransportSample::payload()),
            3,
        )->willReturnCallback(
            function (): array {
                $this->log[] = 'open';

                return self::attemptRow();
            },
        );
        $attempts->expects(self::once())->method('markSent')
            ->with(self::ATTEMPT, self::CORRELATION, 200, 0)
            ->willReturnCallback(function (): array {
                $this->log[] = 'sent';

                return self::attemptRow([
                    'status' => 'awaiting_protocol',
                    'correlation_reference' => self::CORRELATION,
                    'row_version' => 1,
                ]);
            });
        $attempts->expects(self::never())->method('markFailed');

        $outcome = $this->send($this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], self::acknowledgement()),
        ]));

        self::assertSame(['open', 'http', 'sent'], $this->log);
        self::assertSame(
            'https://t-epodani.cssz.cz/VREP/submission',
            (string) $this->history[0]['request']->getUri(),
        );
        self::assertStringContainsString(
            '<Class>CSSZ_JMHZ</Class>',
            (string) $this->history[0]['request']->getBody(),
        );
        self::assertNotNull($outcome->acknowledgement);
        self::assertSame(self::CORRELATION, $outcome->acknowledgement->correlationId);
        self::assertSame('awaiting_protocol', $outcome->attempt['status']);
        self::assertFalse($outcome->isSettled());
    }

    /**
     * Druhé kliknutí na „Odeslat" nesmí založit druhé podání za totéž období —
     * ČSSZ ho odmítne jako duplicitu (chyba 20022). Když ledger vrátí už
     * otevřený pokus, na VREP se nesahá vůbec.
     */
    public function testSendWithAKeyThatAlreadyWentThroughDoesNotSendAgain(): void
    {
        $attempts = $this->attempts();
        $attempts->method('nextAttemptNo')->willReturn(1);
        $attempts->expects(self::once())->method('open')->willReturn(self::attemptRow([
            'status' => 'awaiting_protocol',
            'correlation_reference' => self::CORRELATION,
            'row_version' => 1,
        ]));
        $attempts->expects(self::never())->method('markSent');
        $attempts->expects(self::never())->method('markFailed');

        $outcome = $this->send($this->service($attempts, []));

        self::assertSame([], $this->history);
        self::assertNotContains('http', $this->log);
        self::assertSame(self::CORRELATION, $outcome->attempt['correlation_reference']);
        self::assertNull($outcome->acknowledgement);
    }

    /**
     * Odpověď, která není potvrzením převzetí, znamená pokus bez correlation
     * reference — ten se už nikdy nedohledá ani neuzavře. Musí tedy skončit
     * v ledgeru jako neúspěch s kódem, jinak nelze rozhodnout, jestli se smí
     * opakovat.
     */
    public function testSendRecordsAFailureWhenVrepAnswersWithSomethingElse(): void
    {
        $attempts = $this->openedAttempts();
        $attempts->expects(self::never())->method('markSent');
        $this->captureFailure($attempts);

        $service = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], JmhzTransportSample::partialProtocol(
                result: 'ERROR',
                qualifier: 'error',
            )),
        ]);

        self::assertSame(
            'jmhz_dispatch_rejected',
            $this->failedSend($service, JmhzTransportException::class)->errorCode,
        );
        $this->assertFailureRecorded('jmhz_dispatch_rejected', 200);
    }

    /**
     * HTTP 5xx znamená, že brána požadavek přijala a o jeho osudu nic neřekla.
     * Do ledgeru jde „možná doručeno" i s HTTP statusem protistrany a původní
     * výjimka jde dál. Zapsat to jako `failed` bez `sent_at` by bráně řeklo
     * „nic neodešlo" a pustila by opakování.
     */
    public function testSendRecordsPossibleDeliveryAndRethrowsWhenTheGatewayAnswers5xx(): void
    {
        $attempts = $this->openedAttempts();
        $attempts->expects(self::never())->method('markSent');
        $attempts->expects(self::never())->method('markFailed');
        $this->capturePossibleDelivery($attempts);

        $service = $this->service($attempts, [
            new Response(500, ['Content-Type' => 'text/html'], 'chyba brány'),
        ]);

        $exception = $this->failedSend($service, JmhzTransportException::class);

        self::assertSame('jmhz_vrep_http_error', $exception->errorCode);
        self::assertSame(500, $exception->remoteHttpStatus);
        self::assertTrue($exception->possiblyDelivered);
        $this->assertPossibleDeliveryRecorded('jmhz_vrep_http_error', 500, null);
    }

    /**
     * Vypršení času před hlavičkami odpovědi: požadavek odešel, odpověď
     * nepřišla. ČSSZ ho mohla převzít, takže nejde o „nic neodešlo".
     */
    public function testReadTimeoutAfterTheRequestWentOutIsPossiblyDelivered(): void
    {
        $attempts = $this->openedAttempts();
        $attempts->expects(self::never())->method('markFailed');
        $this->capturePossibleDelivery($attempts);

        $service = $this->service($attempts, [
            new NetworkTimeoutException(
                'cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received',
                new Request('POST', 'https://t-epodani.cssz.cz/VREP/submission'),
            ),
        ]);

        $exception = $this->failedSend($service, JmhzTransportException::class);

        self::assertSame('jmhz_vrep_response_lost', $exception->errorCode);
        self::assertTrue($exception->possiblyDelivered);
        $this->assertPossibleDeliveryRecorded('jmhz_vrep_response_lost', null, null);
    }

    /**
     * Nenavázané spojení: neodešel ani bajt, u ČSSZ po pokusu nic není
     * a opakování nemůže nic zdvojit. Tady `failed` bez `sent_at` zůstává.
     */
    public function testConnectionThatNeverOpenedIsAnOrdinaryFailure(): void
    {
        $attempts = $this->openedAttempts();
        $attempts->expects(self::never())->method('markPossiblyDelivered');
        $this->captureFailure($attempts);

        $service = $this->service($attempts, [
            new ConnectException(
                'cURL error 7: Failed to connect',
                new Request('POST', 'https://t-epodani.cssz.cz/VREP/submission'),
            ),
        ]);

        $exception = $this->failedSend($service, JmhzTransportException::class);

        self::assertSame('jmhz_vrep_unavailable', $exception->errorCode);
        self::assertFalse($exception->possiblyDelivered);
        $this->assertFailureRecorded('jmhz_vrep_unavailable', null);
    }

    /**
     * REGRESE: HTTP 200 s tělem, které není XML (chybová stránka brány).
     *
     * Obálka je v tu chvíli U ČSSZ, jen se nedá přečíst odpověď. Dokud zápis
     * neúspěchu pokrýval jen samotné volání VREP, zůstal řádek ve stavu
     * `prepared` — obsluha viděla neodeslaný pokus, odeslala znovu a ČSSZ
     * druhé podání odmítla jako duplicitu.
     */
    public function testSendRecordsPossibleDeliveryWhenTheAnswerIsNotXmlAtAll(): void
    {
        $attempts = $this->openedAttempts();
        $attempts->expects(self::never())->method('markSent');
        $attempts->expects(self::never())->method('markFailed');
        $this->capturePossibleDelivery($attempts);

        $service = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/html'], '<html><br>502 Bad Gateway<p>brána nedostupná</html>'),
        ]);

        self::assertSame(
            'jmhz_acknowledgement_unreadable',
            $this->failedSend($service, JmhzTransportException::class)->errorCode,
        );
        $this->assertPossibleDeliveryRecorded('jmhz_acknowledgement_unreadable', 200, null);
    }

    /**
     * REGRESE: potvrzení převzetí bez CorrelationID.
     *
     * Nejzákeřnější varianta téhož: odpověď je tvarem v pořádku, jen pod ní
     * není identifikátor, pod kterým by se podání dalo dohledat a uzavřít.
     * Bez zápisu do ledgeru je takový pokus ztracený úplně.
     */
    public function testSendRecordsPossibleDeliveryWhenTheAcknowledgementCarriesNoCorrelation(): void
    {
        $attempts = $this->openedAttempts();
        $attempts->expects(self::never())->method('markSent');
        $this->capturePossibleDelivery($attempts);

        $service = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], str_replace(
                '<CorrelationID>' . self::CORRELATION . '</CorrelationID>',
                '<CorrelationID></CorrelationID>',
                self::acknowledgement(),
            )),
        ]);

        self::assertSame(
            'jmhz_acknowledgement_correlation_missing',
            $this->failedSend($service, JmhzTransportException::class)->errorCode,
        );
        $this->assertPossibleDeliveryRecorded('jmhz_acknowledgement_correlation_missing', 200, null);
    }

    /**
     * REGRESE: ztracený optimistický zámek při zápisu odeslání.
     *
     * Podání prošlo, potvrzení dorazilo — a `markSent` neuspěje, protože řádek
     * mezitím posunul jiný běh. Ani tohle není „nezahájený" pokus: ledger musí
     * dostat „možná doručeno" s kódem `jmhz_dispatch_send_unresolved`
     * a CorrelationID z potvrzení, podle kterého jde protokol dohledat.
     * Volajícímu se musí vrátit původní chyba, ne její náhrada.
     */
    public function testSendRecordsPossibleDeliveryWhenTheLedgerLosesTheRaceAfterTheEnvelopeWentOut(): void
    {
        $lock = new \DomainException('Pokus o odeslání #7 byl mezitím změněn.');
        $attempts = $this->openedAttempts();
        $attempts->expects(self::once())->method('markSent')->willThrowException($lock);
        $this->capturePossibleDelivery($attempts);

        $service = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], self::acknowledgement()),
        ]);

        self::assertSame($lock, $this->failedSend($service, \DomainException::class));
        $this->assertPossibleDeliveryRecorded('jmhz_dispatch_send_unresolved', 200, self::CORRELATION);
    }

    /**
     * REGRESE: zápis neúspěchu nesmí přebít původní chybu.
     *
     * Když je ledger nedostupný přesně v okamžik, kdy se do něj zapisuje pád,
     * volající by se místo skutečné příčiny dozvěděl o problému s databází —
     * a hledal by ji na nesprávném místě.
     */
    public function testFailureBookkeepingNeverReplacesTheOriginalError(): void
    {
        $lock = new \DomainException('Pokus o odeslání #7 byl mezitím změněn.');
        $attempts = $this->openedAttempts();
        $attempts->expects(self::once())->method('markSent')->willThrowException($lock);
        $this->capturePossibleDelivery($attempts, new \RuntimeException('Ledger je nedostupný.'));

        $service = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], self::acknowledgement()),
        ]);

        self::assertSame($lock, $this->failedSend($service, \DomainException::class));
        $this->assertPossibleDeliveryRecorded('jmhz_dispatch_send_unresolved', 200, self::CORRELATION);
    }

    /**
     * Druhé odeslání podání, jehož pokus je „možná doručeno", musí skončit
     * dřív, než se otevře ledger nebo cokoli odejde. Podání přitom zůstává
     * `ready`, takže samotná kontrola stavu by ho pustila.
     */
    public function testSendRefusesWhileAnEarlierAttemptIsPossiblyDelivered(): void
    {
        $attempts = $this->attempts();
        $attempts->method('listForSubmission')->willReturn([
            self::attemptRow([
                'status' => 'possibly_delivered',
                'error_code' => 'jmhz_vrep_response_lost',
                'error_message' => 'Odpověď nedorazila.',
                'row_version' => 1,
            ]),
        ]);
        $attempts->expects(self::never())->method('open');

        $service = $this->service($attempts, []);
        $exception = $this->failedSend($service, \DomainException::class);

        self::assertStringContainsString('možná doručeno', $exception->getMessage());
        self::assertSame([], $this->history, 'Na VREP nesmělo odejít nic.');
    }

    /**
     * Po výslovném potvrzení účetní se posílá TÝŽ zmrazený dokument, tedy se
     * stejným GUID podání; pokud originál u ČSSZ je, odpoví 20022.
     */
    public function testSendIsAllowedOnceTheRetryWasExplicitlyConfirmed(): void
    {
        $attempts = $this->openedAttempts();
        $attempts->method('listForSubmission')->willReturn([
            self::attemptRow([
                'status' => 'expired',
                'error_code' => 'retry_confirmed_by_user',
                'error_message' => 'Protokol u ČSSZ není.',
                'row_version' => 2,
            ]),
        ]);
        $attempts->method('markSent')->willReturn(self::sentRow(['attempt_no' => 2]));

        $outcome = $this->send($this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], self::acknowledgement()),
        ]));

        self::assertSame(self::CORRELATION, $outcome->acknowledgement?->correlationId);
    }

    /**
     * Opakované odeslání, na které ČSSZ hned odpoví jen kontrolou 22 ve
     * variantě „shodné R už existuje": originál je u ČSSZ. Není to zamítnutí,
     * po kterém se dá zahodit a poslat nové, ale stav, který blokuje další
     * odeslání a žádá protokol originálu.
     */
    public function testImmediateDuplicateAnswerMeansTheOriginalIsAtCssz(): void
    {
        $attempts = $this->openedAttempts();
        $attempts->expects(self::never())->method('markFailed');
        $this->capturePossibleDelivery($attempts);

        $service = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], JmhzTransportSample::partialProtocol(
                result: 'ERROR',
                qualifier: 'error',
                errMsg: 'JMHZ25_LT_G: 20022 - Podání typu R se stejným idPodani,'
                    . ' variabilním symbolem, obdobím a balík pořadí již existuje',
                errNumber: '20022',
            )),
        ]);

        self::assertSame(
            PayrollDispatchGate::ORIGINAL_AT_CSSZ_ERROR_CODE,
            $this->failedSend($service, JmhzTransportException::class)->errorCode,
        );
        $this->assertPossibleDeliveryRecorded(PayrollDispatchGate::ORIGINAL_AT_CSSZ_ERROR_CODE, 200, null);
    }

    /**
     * Dokud VREP odpovídá potvrzením, zpracování běží dál. Uzavřít pokus v ten
     * okamžik znamená vydat za výsledek něco, co ještě neexistuje.
     */
    public function testPollLeavesTheAttemptOpenWhileProcessingStillRuns(): void
    {
        $attempts = $this->attempts();
        $attempts->method('find')->willReturn(self::sentRow());
        $attempts->expects(self::never())->method('markCompleted');

        $outcome = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], self::acknowledgement()),
        ])->poll(
            self::SUPPLIER,
            'test',
            self::ATTEMPT,
            JmhzTransportSample::VARIABLE_SYMBOL,
        );

        self::assertSame(
            'https://t-epodani.cssz.cz/VREP/poll',
            (string) $this->history[0]['request']->getUri(),
        );
        self::assertNotNull($outcome->acknowledgement);
        self::assertNull($outcome->report);
        self::assertFalse($outcome->isSettled());
        self::assertSame('awaiting_protocol', $outcome->attempt['status']);
    }

    /** Až protokol o zpracování je výsledek — teprve tehdy se pokus uzavírá. */
    public function testPollCompletesTheAttemptOnceTheProtocolArrives(): void
    {
        $attempts = $this->attempts();
        $attempts->method('find')->willReturn(self::sentRow());
        $attempts->expects(self::once())->method('markCompleted')
            ->with(self::ATTEMPT, 1)
            ->willReturn(self::sentRow(['status' => 'completed', 'row_version' => 2]));

        $outcome = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], JmhzTransportSample::partialProtocol()),
        ])->poll(
            self::SUPPLIER,
            'test',
            self::ATTEMPT,
            JmhzTransportSample::VARIABLE_SYMBOL,
        );

        self::assertNull($outcome->acknowledgement);
        self::assertNotNull($outcome->report);
        self::assertSame(
            JmhzSubmissionStatus::ProcessedAndComplete,
            $outcome->report->status,
        );
        self::assertTrue($outcome->isSettled());
        self::assertSame('completed', $outcome->attempt['status']);
    }

    public function testPollRejectsAProtocolForAnotherSubmissionClass(): void
    {
        $attempts = $this->attempts();
        $attempts->method('find')->willReturn(self::sentRow());
        $attempts->expects(self::never())->method('markCompleted');

        try {
            $this->service($attempts, [
                new Response(
                    200,
                    ['Content-Type' => 'text/xml'],
                    JmhzTransportSample::partialProtocol(),
                ),
            ])->poll(
                self::SUPPLIER,
                'test',
                self::ATTEMPT,
                JmhzTransportSample::VARIABLE_SYMBOL,
                1,
                'CSSZ_PREZEC',
            );
            self::fail('Protokol JMHZ nesmí uzavřít pokus PREZEC.');
        } catch (JmhzTransportException $exception) {
            self::assertSame(
                'jmhz_protocol_class_mismatch',
                $exception->errorCode,
            );
        }
    }

    /**
     * Dotažený protokol musí podání posunout, jinak zůstane navždy „odesláno".
     * Kontroluje se, že se import volá S ověřovatelem podpisu — bez něj by se
     * `remote_status` do platformy nedostal a celý dotaz by byl zbytečný.
     */
    public function testSettledPollHandsTheProtocolToTheSubmissionPlatform(): void
    {
        $attempts = $this->attempts();
        $attempts->method('find')->willReturn(self::sentRow());
        $attempts->expects(self::once())->method('markCompleted')->willReturn(
            self::sentRow(['status' => 'completed', 'row_version' => 2]),
        );

        $submissions = $this->createMock(PayrollSubmissionService::class);
        $submissions->method('get')->willReturn([
            'id' => self::SUBMISSION,
            'status' => 'submitted',
            'row_version' => 7,
        ]);
        $submissions->expects(self::once())->method('importReceipt')->with(
            self::SUPPLIER,
            self::SUBMISSION,
            7,
            null,
            self::anything(),
            self::CORRELATION,
            self::CORRELATION,
            'CSSZ_JMHZ',
            'accepted',
            JmhzDispatchService::CHANNEL,
            self::anything(),
            null,
            self::isInstanceOf(JmhzReceiptVerifier::class),
        )->willReturn([
            'submission_status' => 'accepted',
            'submission_row_version' => 8,
            'trusted' => true,
        ]);

        $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], JmhzTransportSample::partialProtocol()),
        ], null, $submissions)->poll(
            self::SUPPLIER,
            'test',
            self::ATTEMPT,
            JmhzTransportSample::VARIABLE_SYMBOL,
        );
    }

    /**
     * Pokus „možná doručeno" s CorrelationID (potvrzení dorazilo, spadl
     * zápis) se primárně DOHLEDÁVÁ: dotaz na stav dotáhne protokol, podání
     * se posune na „odesláno" a protokol se předá platformě.
     */
    public function testPollOfPossiblyDeliveredAttemptFindsTheProtocolAndMarksTheSubmission(): void
    {
        $attempts = $this->attempts();
        $attempts->method('find')->willReturn(self::sentRow([
            'status' => 'possibly_delivered',
            'error_code' => 'jmhz_dispatch_send_unresolved',
            'error_message' => 'Zápis odeslání spadl.',
        ]));
        $attempts->expects(self::once())->method('markCompleted')->willReturn(
            self::sentRow(['status' => 'completed', 'row_version' => 2]),
        );

        $submissions = $this->createMock(PayrollSubmissionService::class);
        $submissions->method('get')->willReturn([
            'id' => self::SUBMISSION,
            'status' => 'ready',
            'row_version' => 7,
        ]);
        $submissions->expects(self::once())->method('transition')->with(
            self::SUPPLIER,
            self::SUBMISSION,
            7,
            'submitted',
            self::CORRELATION,
        )->willReturn(['id' => self::SUBMISSION, 'status' => 'submitted', 'row_version' => 8]);
        $submissions->expects(self::once())->method('importReceipt')->willReturn([
            'submission_status' => 'accepted',
            'submission_row_version' => 9,
            'trusted' => true,
        ]);

        $outcome = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], JmhzTransportSample::partialProtocol()),
        ], null, $submissions)->poll(
            self::SUPPLIER,
            'test',
            self::ATTEMPT,
            JmhzTransportSample::VARIABLE_SYMBOL,
        );

        self::assertTrue($outcome->isSettled());
    }

    /**
     * Protokol, jehož jedinou chybou je 20022 „shodné R už existuje", NENÍ
     * zamítnutí: platformě jde jako „odesláno" a podání dostane nález, že
     * originál je u ČSSZ a má se doložit jeho protokol.
     */
    public function testDuplicateOnlyProtocolKeepsTheSubmissionSubmittedAndAsksForTheOriginal(): void
    {
        $attempts = $this->attempts();
        $attempts->method('find')->willReturn(self::sentRow());
        $attempts->expects(self::once())->method('markCompleted')->willReturn(
            self::sentRow(['status' => 'completed', 'row_version' => 2]),
        );

        $submissions = $this->createMock(PayrollSubmissionService::class);
        $submissions->method('get')->willReturn([
            'id' => self::SUBMISSION,
            'status' => 'submitted',
            'row_version' => 7,
        ]);
        $submissions->expects(self::once())->method('importReceipt')->with(
            self::SUPPLIER,
            self::SUBMISSION,
            7,
            null,
            self::anything(),
            self::CORRELATION,
            self::CORRELATION,
            'CSSZ_JMHZ',
            'submitted',
            JmhzDispatchService::CHANNEL,
            self::anything(),
            null,
            self::isInstanceOf(JmhzReceiptVerifier::class),
        )->willReturn([
            'submission_status' => 'submitted',
            'submission_row_version' => 8,
            'trusted' => true,
        ]);
        $submissions->expects(self::once())->method('recordIssue')->with(
            self::SUPPLIER,
            self::SUBMISSION,
            7,
            null,
            'warning',
            'remote',
            PayrollDispatchGate::ORIGINAL_AT_CSSZ_ERROR_CODE,
        );

        $outcome = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], JmhzTransportSample::partialProtocol(
                result: 'ERROR',
                qualifier: 'error',
                errMsg: 'JMHZ25_LT_G: 20022 - Podání typu R se stejným idPodani,'
                    . ' variabilním symbolem, obdobím a balík pořadí již existuje',
                errNumber: '20022',
                generalResult: 'ERROR',
                correlationId: self::CORRELATION,
            )),
        ], null, $submissions)->poll(
            self::SUPPLIER,
            'test',
            self::ATTEMPT,
            JmhzTransportSample::VARIABLE_SYMBOL,
        );

        self::assertNotNull($outcome->report);
        self::assertTrue($outcome->report->originalAlreadyAtCssz());
    }

    /**
     * Když ověření podpisu neprojde, protokol se NESMÍ zahodit ani prohlásit
     * za důvěryhodný — uloží se znovu bez verifieru, tedy jako příloha bez
     * důkazní síly, a k obecnému `receipt_unverified` se přidá pojmenovaný
     * důvod.
     */
    public function testUnverifiableProtocolIsStoredWithoutMovingTheSubmission(): void
    {
        $attempts = $this->attempts();
        $attempts->method('find')->willReturn(self::sentRow());
        $attempts->expects(self::once())->method('markCompleted')->willReturn(
            self::sentRow(['status' => 'completed', 'row_version' => 2]),
        );

        $verifiers = [];
        $submissions = $this->createMock(PayrollSubmissionService::class);
        $submissions->method('get')->willReturn([
            'id' => self::SUBMISSION,
            'status' => 'submitted',
            'row_version' => 7,
        ]);
        $submissions->expects(self::exactly(2))->method('importReceipt')
            ->willReturnCallback(
                function (...$arguments) use (&$verifiers): array {
                    $verifiers[] = $arguments[12] ?? null;
                    if ($arguments[12] !== null) {
                        // Vzorek protokolu žádný podpis nenese.
                        throw new JmhzTransportException(
                            'jmhz_protocol_signature_missing',
                            'Protokol ČSSZ neobsahuje podepsanou časovou značku.',
                        );
                    }

                    return [
                        'submission_status' => 'submitted',
                        'submission_row_version' => 8,
                        'trusted' => false,
                    ];
                },
            );
        $submissions->expects(self::once())->method('recordIssue')->with(
            self::SUPPLIER,
            self::SUBMISSION,
            7,
            null,
            'error',
            'remote',
            'jmhz_protocol_signature_missing',
        )->willReturn(['id' => 1, 'submission_row_version' => 9]);

        $outcome = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], JmhzTransportSample::partialProtocol()),
        ], null, $submissions)->poll(
            self::SUPPLIER,
            'test',
            self::ATTEMPT,
            JmhzTransportSample::VARIABLE_SYMBOL,
        );

        // Výsledek dotazu se kvůli neověřenému podpisu neztrácí: ledger má
        // pokus za vyřízený a report je pořád k dispozici.
        self::assertTrue($outcome->isSettled());
        self::assertInstanceOf(JmhzReceiptVerifier::class, $verifiers[0]);
        self::assertNull($verifiers[1]);
    }

    /**
     * Transakce se uzavírá FUNKCÍ `delete`, kvalifikátor zůstává `poll`.
     * Zjištěno pokusem proti testovacímu VREP: `Qualifier=delete` vrátí
     * „Invalid qualifier" a transakce zůstane viset — což podací protokol
     * výslovně zakazuje. Proto se kontroluje odeslané tělo, ne záměr.
     */
    public function testCloseSendsDeleteAsFunctionAndKeepsPollAsQualifier(): void
    {
        $attempts = $this->attempts();
        $attempts->method('find')->willReturn(self::completedRow());
        $attempts->expects(self::never())->method('markCompleted');
        // Uzavření se musí zapsat do ledgeru: neuzavřená transakce je porušení
        // pravidel provozu a bez záznamu by se nedalo poznat, které ještě visí.
        $attempts->expects(self::once())->method('markClosed')
            ->with(self::ATTEMPT, 2)
            ->willReturn(self::completedRow([
                'closed_at' => '2026-04-11 08:31:00',
                'close_attempts' => 1,
                'row_version' => 3,
            ]));

        $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], '<GovTalkMessage/>'),
        ])->close(
            self::SUPPLIER,
            'test',
            self::ATTEMPT,
            JmhzTransportSample::VARIABLE_SYMBOL,
        );

        self::assertCount(1, $this->history);
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML((string) $this->history[0]['request']->getBody()));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('g', JmhzGovTalkEnvelope::NS_GOVTALK);

        self::assertSame('poll', $this->text($xpath, '//g:MessageDetails/g:Qualifier'));
        self::assertSame('delete', $this->text($xpath, '//g:MessageDetails/g:Function'));
        self::assertSame(self::CORRELATION, $this->text($xpath, '//g:MessageDetails/g:CorrelationID'));
    }

    /**
     * Bez zvoleného certifikátu se nemá co odeslat — a hlavně nesmí vzniknout
     * řádek v ledgeru: pokus, který nikdy neopustil proces, by v historii
     * vypadal jako neúspěšné podání a spotřeboval by pořadové číslo.
     */
    public function testMissingSigningProfileRefusesWithoutOpeningTheLedger(): void
    {
        $attempts = $this->attempts();
        $attempts->expects(self::never())->method('nextAttemptNo');
        $attempts->expects(self::never())->method('open');
        $attempts->expects(self::never())->method('markFailed');

        $profiles = $this->createStub(PayrollSigningProfileRepository::class);
        $profiles->method('find')->willReturn(null);

        $exception = $this->failedSend(
            $this->service($attempts, [], $profiles),
            JmhzTransportException::class,
        );

        self::assertSame('jmhz_signing_profile_missing', $exception->errorCode);
        self::assertSame([], $this->history);
    }

    /**
     * W13/P-04. Datová věta z requestu se na VREP NESMÍ dostat. Dřív se
     * posílalo přesně to, co přišlo — bez XSD, bez katalogu kontrol — a do
     * ledgeru se zapsal otisk TOHO, co přišlo, takže archiv pak tvrdil, že
     * odesláno bylo zmrazené XML, i když odesláno bylo něco jiného.
     */
    public function testSendRefusesAPayloadThatDoesNotMatchTheFrozenArtifact(): void
    {
        $attempts = $this->attempts();
        $attempts->expects(self::never())->method('open');
        $attempts->expects(self::never())->method('markFailed');

        $service = $this->service(
            $attempts,
            [],
            null,
            $this->platform(),
            $this->frozen(JmhzTransportSample::payload()),
        );

        try {
            $service->send(
                self::SUPPLIER,
                'test',
                self::SUBMISSION,
                '<jmhz>podvržená datová věta</jmhz>',
                JmhzTransportSample::VARIABLE_SYMBOL,
                'jmhz-2026-04-11',
                3,
            );
            self::fail('Podvržená datová věta neměla projít.');
        } catch (JmhzTransportException $exception) {
            self::assertSame('jmhz_dispatch_payload_not_frozen', $exception->errorCode);
        }
        self::assertSame([], $this->history);
    }

    /**
     * Odesílá se zmrazený artefakt a do ledgeru jde JEHO otisk — i když
     * volající žádnou datovou větu nepředal.
     */
    public function testSendTakesThePayloadFromTheFrozenArchive(): void
    {
        $attempts = $this->attempts();
        $attempts->method('nextAttemptNo')->willReturn(1);
        $attempts->expects(self::once())->method('open')->with(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            JmhzDispatchService::CHANNEL,
            1,
            'jmhz-2026-04-11',
            hash('sha256', JmhzTransportSample::payload()),
            3,
        )->willReturn(self::attemptRow());
        $attempts->expects(self::once())->method('markSent')->willReturn(
            self::attemptRow([
                'status' => 'awaiting_protocol',
                'correlation_reference' => self::CORRELATION,
                'row_version' => 1,
            ]),
        );

        $service = $this->service(
            $attempts,
            [new Response(200, ['Content-Type' => 'text/xml'], self::acknowledgement())],
            null,
            $this->platform(),
            $this->frozen(JmhzTransportSample::payload()),
        );

        $outcome = $service->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            null,
            JmhzTransportSample::VARIABLE_SYMBOL,
            'jmhz-2026-04-11',
            3,
        );

        self::assertNotNull($outcome->acknowledgement);
    }

    /**
     * W13/P-05. Podání jiné agendy (OZUSPOJ) se pod hlavičkou
     * `Class=CSSZ_JMHZ` odeslat NESMÍ. Kanál `vrep_apep` je společný, takže
     * bez kontroly agendy stačilo poslat cizí ID podání a ČSSZ dostala
     * dokument, který k deklarovanému druhu podání nepatří.
     */
    public function testSendRefusesASubmissionOfAnotherAgenda(): void
    {
        $attempts = $this->attempts();
        $attempts->expects(self::never())->method('open');

        $service = $this->service(
            $attempts,
            [],
            null,
            $this->platform(agendaCode: 'OZUSPOJ23'),
            $this->frozen(JmhzTransportSample::payload()),
        );

        $this->expectException(\DomainException::class);
        try {
            $this->send($service);
        } finally {
            self::assertSame([], $this->history);
        }
    }

    /** Kanál mimo VREP/APEP touhle cestou neodchází. */
    public function testSendRefusesASubmissionFromAnotherChannel(): void
    {
        $attempts = $this->attempts();
        $attempts->expects(self::never())->method('open');

        $service = $this->service(
            $attempts,
            [],
            null,
            $this->platform(channel: 'isds'),
            $this->frozen(JmhzTransportSample::payload()),
        );

        $this->expectException(\DomainException::class);
        $this->send($service);
    }

    /**
     * Stav se kontroluje PŘED odesláním, ne až v evidenci po něm. Podání,
     * které už bylo odesláno, se pod novým idempotenčním klíčem neodešle
     * podruhé — ČSSZ by druhé podání odmítla jako duplicitu.
     */
    public function testSendRefusesASubmissionThatIsNotReady(): void
    {
        $attempts = $this->attempts();
        $attempts->method('findByIdempotencyKey')->willReturn(null);
        $attempts->expects(self::never())->method('open');

        $service = $this->service(
            $attempts,
            [],
            null,
            $this->platform(status: 'submitted'),
            $this->frozen(JmhzTransportSample::payload()),
        );

        $this->expectException(\DomainException::class);
        $this->send($service);
    }

    /**
     * Opakované volání s TÝMŽ klíčem musí projít i u odeslaného podání: není
     * to nové odeslání, ale vrácení původního pokusu.
     */
    public function testSendReplaysTheOriginalAttemptOfAnAlreadySubmittedSubmission(): void
    {
        $attempts = $this->attempts();
        $attempts->method('findByIdempotencyKey')->willReturn(self::attemptRow([
            'status' => 'awaiting_protocol',
            'correlation_reference' => self::CORRELATION,
        ]));
        $attempts->method('nextAttemptNo')->willReturn(1);
        $attempts->expects(self::once())->method('open')->willReturn(self::attemptRow([
            'status' => 'awaiting_protocol',
            'correlation_reference' => self::CORRELATION,
        ]));

        $outcome = $this->send($this->service(
            $attempts,
            [],
            null,
            $this->platform(status: 'submitted'),
            $this->frozen(JmhzTransportSample::payload()),
        ));

        self::assertSame('awaiting_protocol', $outcome->attempt['status']);
        self::assertSame([], $this->history);
    }

    /**
     * W13/C-12. Když po ÚSPĚŠNÉM odeslání selže evidence, nesmí to zmizet:
     * podání by zůstalo `ready`, obsluha by odeslala znovu a ČSSZ by druhé
     * podání odmítla jako duplicitu. Selhání proto musí být dohledatelné
     * jako pojmenovaný provozní nález.
     */
    public function testFailedBookkeepingAfterASuccessfulSendIsRecordedAsAnIssue(): void
    {
        $attempts = $this->attempts();
        $attempts->method('nextAttemptNo')->willReturn(1);
        $attempts->expects(self::once())->method('open')
            ->willReturn(self::attemptRow());
        $attempts->expects(self::once())->method('markSent')->willReturn(
            self::attemptRow([
                'status' => 'awaiting_protocol',
                'correlation_reference' => self::CORRELATION,
                'row_version' => 1,
            ]),
        );

        $submissions = $this->platform();
        $submissions->method('transition')->willThrowException(
            new \RuntimeException('Optimistický zámek podání selhal.'),
        );
        $recorded = null;
        $submissions->expects(self::once())->method('recordIssue')
            ->willReturnCallback(function (...$arguments) use (&$recorded): array {
                $recorded = $arguments;

                return ['submission_status' => 'ready', 'submission_row_version' => 8];
            });

        $outcome = $this->send($this->service(
            $attempts,
            [new Response(200, ['Content-Type' => 'text/xml'], self::acknowledgement())],
            null,
            $submissions,
            $this->frozen(JmhzTransportSample::payload()),
        ));

        // Odeslání proběhlo a chybou evidence se shodit nesmí.
        self::assertNotNull($outcome->acknowledgement);
        self::assertIsArray($recorded);
        self::assertSame('error', $recorded[4]);
        self::assertSame('jmhz_dispatch_submitted_not_recorded', $recorded[6]);
    }

    /**
     * Odeslání, u kterého se čeká pád. Vrací chycenou výjimku, ať se dá dál
     * zkoumat; když nespadne nic, je to samo o sobě selhání testu.
     *
     * @template T of \Throwable
     * @param class-string<T> $expected
     * @return T
     */
    private function failedSend(JmhzDispatchService $service, string $expected): \Throwable
    {
        try {
            $this->send($service);
        } catch (\Throwable $exception) {
            self::assertInstanceOf($expected, $exception);

            return $exception;
        }

        self::fail('Odeslání mělo skončit výjimkou ' . $expected . '.');
    }

    /**
     * Zkušební podání ČSSZ TEST (8. 10. 2026): protokol k registraci nechal
     * pokus navždy ve stavu „čeká na protokol", protože ho parser četl jako
     * JMHZ. Teď ho dotaz dotáhne a pokus uzavře.
     */
    public function testRegistrationProtocolCompletesTheAttempt(): void
    {
        $attempts = $this->attempts();
        $attempts->method('find')->willReturn(self::sentRow());
        $attempts->expects(self::once())->method('markCompleted')
            ->willReturn(self::sentRow(['status' => 'completed', 'row_version' => 2]));

        $outcome = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], self::csszAnswer(
                'CSSZ_REGZEC',
                'error',
                '<ProcessingResult type="CSSZ_REGZEC" version="1,0" result="ERROR"'
                    . ' errMsg="REGZEC25_LT: 103901602 - Osoba nebyla nalezena." errNumber="5"'
                    . ' count="1" countErr="1" countWar="0"><Details>'
                    . '<Item sqnr="" identifier="" subtype="REGZEC25" result="OK" errMsg="" errNum="" />'
                    . '<Item sqnr="1" identifier="7001010001;;" subtype="REGZEC25" result="ERROR"'
                    . ' errMsg="REGZEC25_LT: 103901602 - Osoba nebyla nalezena." errNum="602" />'
                    . '</Details></ProcessingResult>',
            )),
        ])->poll(self::SUPPLIER, 'test', self::ATTEMPT, '1234567890', 1, 'CSSZ_REGZEC');

        self::assertTrue($outcome->isSettled());
        self::assertSame(JmhzSubmissionStatus::Rejected, $outcome->report?->status);
        self::assertSame('completed', $outcome->attempt['status']);
    }

    /**
     * NEMPRI/HZUPN odchází s třídou CSSZ_NEM_PRI a eType podle formuláře —
     * obě agendy třídu sdílí, takže bez formuláře by se obálka nepostavila.
     */
    public function testSicknessFormIsSentWithTheSharedClassAndItsOwnEnvelopeType(): void
    {
        $payload = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<PodaniHZUPN xmlns="http://schemas.cssz.cz/nem/HZUPN20" version="1.2">'
            . '<FormularHZUPN poradoveCislo="1"><zamestnani><variabilniSymbol>'
            . JmhzTransportSample::VARIABLE_SYMBOL
            . '</variabilniSymbol></zamestnani></FormularHZUPN></PodaniHZUPN>';
        $attempts = $this->openedAttempts();
        $attempts->expects(self::once())->method('markSent')->willReturn(self::sentRow());

        $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], str_replace(
                '<Class>CSSZ_JMHZ</Class>',
                '<Class>CSSZ_NEM_PRI</Class>',
                self::acknowledgement(),
            )),
        ])->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            $payload,
            JmhzTransportSample::VARIABLE_SYMBOL,
            'hzupn-click-1',
            3,
            'CSSZ_NEM_PRI',
            'HZUPN20',
        );

        $sent = (string) $this->history[0]['request']->getBody();
        self::assertStringContainsString('<Class>CSSZ_NEM_PRI</Class>', $sent);
        self::assertStringContainsString('eType="HZUPN20"', $sent);
    }

    public function testSharedClassWithoutFormNeverOpensTheLedger(): void
    {
        $attempts = $this->attempts();
        $attempts->expects(self::never())->method('open');

        try {
            $this->service($attempts, [])->send(
                self::SUPPLIER,
                'test',
                self::SUBMISSION,
                JmhzTransportSample::payload(),
                JmhzTransportSample::VARIABLE_SYMBOL,
                'nempri-no-form',
                3,
                'CSSZ_NEM_PRI',
            );
            self::fail('Bez formuláře nejde rozhodnout mezi NEMPRI25 a HZUPN20.');
        } catch (JmhzTransportException $exception) {
            self::assertSame('jmhz_govtalk_envelope_type_unknown', $exception->errorCode);
        }
        self::assertSame([], $this->history);
    }

    /**
     * Konečná odpověď na NEMPRI/HZUPN v nedoloženém tvaru: pokus se uzavře
     * (transakci jde uzavřít), odpověď se uloží jako neověřený protokol
     * k ruční kontrole a podání dostane pojmenovaný nález. Nezahodí se a nic
     * se z ní nevykládá.
     */
    public function testUndocumentedSicknessProtocolIsStoredForManualReview(): void
    {
        $attempts = $this->attempts();
        $attempts->method('find')->willReturn(self::sentRow());
        $attempts->expects(self::once())->method('markCompleted')
            ->willReturn(self::sentRow(['status' => 'completed', 'row_version' => 2]));
        $submissions = $this->createMock(PayrollSubmissionService::class);
        $submissions->method('get')->willReturn([
            'id' => self::SUBMISSION,
            'status' => 'submitted',
            'row_version' => 7,
        ]);
        $submissions->expects(self::once())->method('importReceipt')->with(
            self::SUPPLIER,
            self::SUBMISSION,
            7,
            null,
            self::anything(),
            self::CORRELATION,
            self::CORRELATION,
            'CSSZ_NEM_PRI',
            'submitted',
            JmhzDispatchService::CHANNEL,
            self::anything(),
            null,
            null,
        )->willReturn(['submission_status' => 'submitted', 'submission_row_version' => 8, 'trusted' => false]);
        $submissions->expects(self::once())->method('recordIssue')->with(
            self::SUPPLIER,
            self::SUBMISSION,
            7,
            null,
            'warning',
            'remote',
            'jmhz_protocol_shape_undocumented',
        );

        $outcome = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], self::csszAnswer(
                'CSSZ_NEM_PRI',
                'response',
                '<ProcessingResult type="CSSZ_NEM_PRI" result="OK" />',
            )),
        ], null, $submissions)->poll(
            self::SUPPLIER,
            'test',
            self::ATTEMPT,
            JmhzTransportSample::VARIABLE_SYMBOL,
            1,
            'CSSZ_NEM_PRI',
            'HZUPN20',
        );

        self::assertTrue($outcome->manualReview);
        self::assertTrue($outcome->isSettled());
        self::assertNull($outcome->report);
        self::assertSame('completed', $outcome->attempt['status']);
    }

    /** Odpověď cizí transakce se k ruční kontrole neuzavírá — pokus zůstává otevřený. */
    public function testUndocumentedProtocolOfAnotherTransactionKeepsTheAttemptOpen(): void
    {
        $attempts = $this->attempts();
        $attempts->method('find')->willReturn(self::sentRow());
        $attempts->expects(self::never())->method('markCompleted');

        try {
            $this->service($attempts, [
                new Response(200, ['Content-Type' => 'text/xml'], str_replace(
                    self::CORRELATION,
                    'FFFF0000FFFF0000FFFF0000FFFF0000',
                    self::csszAnswer('CSSZ_NEM_PRI', 'response', '<ProcessingResult result="OK" />'),
                )),
            ])->poll(
                self::SUPPLIER,
                'test',
                self::ATTEMPT,
                JmhzTransportSample::VARIABLE_SYMBOL,
                1,
                'CSSZ_NEM_PRI',
                'NEMPRI25',
            );
            self::fail('Odpověď cizí transakce nesmí uzavřít náš pokus.');
        } catch (JmhzTransportException $exception) {
            self::assertSame('jmhz_protocol_shape_undocumented', $exception->errorCode);
        }
    }

    private static function csszAnswer(string $class, string $qualifier, string $processingResult): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
            . '<EnvelopeVersion>2.0</EnvelopeVersion><Header><MessageDetails>'
            . "<Class>{$class}</Class><Qualifier>{$qualifier}</Qualifier><Function>submit</Function>"
            . '<CorrelationID>' . self::CORRELATION . '</CorrelationID>'
            . '</MessageDetails></Header><GovTalkDetails><Keys /></GovTalkDetails><Body>'
            . '<Message xmlns="http://www.cssz.cz/XMLSchema/envelope" version="1.2" eType="response">'
            . '<Header /><Body>' . $processingResult . '</Body></Message></Body></GovTalkMessage>';
    }

    private function send(JmhzDispatchService $service): JmhzDispatchOutcome
    {
        return $service->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            JmhzTransportSample::payload(),
            JmhzTransportSample::VARIABLE_SYMBOL,
            'jmhz-2026-04-11',
            3,
        );
    }

    /**
     * Zápis neúspěchu do ledgeru si zapamatuje argumenty. `$throws` simuluje
     * ledger, který v ten okamžik sám selže.
     *
     * @param MockObject&PayrollSubmissionTransportAttemptRepository $attempts
     */
    private function captureFailure(MockObject $attempts, ?\Throwable $throws = null): void
    {
        $attempts->expects(self::once())->method('markFailed')->willReturnCallback(
            function (
                int $attemptId,
                string $errorCode,
                string $errorMessage,
                ?int $httpStatus,
                ?string $nextRetryAt,
                int $expectedVersion,
            ) use ($throws): array {
                $this->failure = [
                    $attemptId,
                    $errorCode,
                    $errorMessage,
                    $httpStatus,
                    $nextRetryAt,
                    $expectedVersion,
                ];
                if ($throws !== null) {
                    throw $throws;
                }

                return self::attemptRow(['status' => 'failed', 'row_version' => 1]);
            },
        );
    }

    /**
     * Zápis „možná doručeno" si zapamatuje argumenty; `$throws` simuluje
     * ledger, který v ten okamžik sám selže.
     *
     * @param MockObject&PayrollSubmissionTransportAttemptRepository $attempts
     */
    private function capturePossibleDelivery(MockObject $attempts, ?\Throwable $throws = null): void
    {
        $attempts->expects(self::once())->method('markPossiblyDelivered')->willReturnCallback(
            function (
                int $attemptId,
                string $errorCode,
                string $errorMessage,
                ?int $httpStatus,
                ?string $correlation,
                int $expectedVersion,
            ) use ($throws): array {
                $this->failure = [
                    $attemptId,
                    $errorCode,
                    $errorMessage,
                    $httpStatus,
                    $correlation,
                    $expectedVersion,
                ];
                if ($throws !== null) {
                    throw $throws;
                }

                return self::attemptRow(['status' => 'possibly_delivered', 'row_version' => 1]);
            },
        );
    }

    private function assertPossibleDeliveryRecorded(
        string $errorCode,
        ?int $httpStatus,
        ?string $correlation,
    ): void {
        self::assertIsArray($this->failure, 'Ledger nedostal zápis „možná doručeno".');
        self::assertSame(self::ATTEMPT, $this->failure[0]);
        self::assertSame($errorCode, $this->failure[1]);
        self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,63}$/D', $this->failure[1]);
        self::assertNotSame('', trim($this->failure[2]));
        self::assertSame($httpStatus, $this->failure[3]);
        self::assertSame($correlation, $this->failure[4]);
        self::assertSame(0, $this->failure[5]);
    }

    private function assertFailureRecorded(string $errorCode, ?int $httpStatus): void
    {
        self::assertIsArray($this->failure, 'Ledger nedostal zápis neúspěchu.');
        self::assertSame(self::ATTEMPT, $this->failure[0]);
        self::assertSame($errorCode, $this->failure[1]);
        // Repozitář kód chyby validuje proti témuž tvaru; kód mimo něj by
        // zápis neúspěchu shodil na DomainException místo uložení.
        self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,63}$/D', $this->failure[1]);
        self::assertNotSame('', trim($this->failure[2]));
        self::assertSame($httpStatus, $this->failure[3]);
        self::assertSame(0, $this->failure[5]);
    }

    /**
     * @param MockObject&PayrollSubmissionTransportAttemptRepository $attempts
     * @param list<mixed> $queue
     */
    private function service(
        PayrollSubmissionTransportAttemptRepository $attempts,
        array $queue,
        ?PayrollSigningProfileRepository $profiles = null,
        ?PayrollSubmissionService $submissions = null,
        ?JmhzFrozenPayloadReader $frozen = null,
    ): JmhzDispatchService {
        $material = self::certificate();

        if ($profiles === null) {
            $profiles = $this->createStub(PayrollSigningProfileRepository::class);
            $profiles->method('find')->willReturn([
                'supplier_id' => self::SUPPLIER,
                'environment' => 'test',
                'credential_id' => 5,
                'owner_user_id' => 3,
                'cssz_registered_serial' => null,
                'row_version' => 1,
            ]);
        }

        $vault = $this->createStub(PersonalCertificateVaultService::class);
        $vault->method('resolve')->willReturn([
            'pfx' => $material['pfx'],
            'password_enc' => 'enc:jmhz',
            'certificate_valid_from' => null,
            'certificate_valid_to' => null,
            'credential' => ['id' => 5, 'serial_hex' => '01'],
        ]);

        $secrets = $this->createStub(SecretEncryption::class);
        $secrets->method('decrypt')->willReturn($material['password']);

        return new JmhzDispatchService(
            $attempts,
            $profiles,
            $vault,
            $secrets,
            new JmhzSoftwareIdentification('MyUcto', '1.0'),
            $this->vrep($queue),
            frozen: $frozen,
            submissions: $submissions,
        );
    }

    /** Archiv zmrazených artefaktů, který vrací dané bajty. */
    private function frozen(string $bytes): JmhzFrozenPayloadReader
    {
        $frozen = $this->createStub(JmhzFrozenPayloadReader::class);
        $frozen->method('bytes')->willReturn($bytes);

        return $frozen;
    }

    /**
     * Platforma podání s daným stavem a agendou.
     *
     * @return MockObject&PayrollSubmissionService
     */
    private function platform(
        string $status = 'ready',
        string $agendaCode = 'JMHZ25',
        string $channel = JmhzDispatchService::CHANNEL,
    ): MockObject {
        $submissions = $this->createMock(PayrollSubmissionService::class);
        // Brána MUSÍ podání přečíst — bez toho by se agenda, kanál ani stav
        // neověřily a odesílalo by se naslepo.
        $submissions->expects(self::atLeastOnce())->method('get')->willReturn([
            'id' => self::SUBMISSION,
            'status' => $status,
            'row_version' => 7,
            'channel' => $channel,
            'environment' => 'test',
        ]);
        $submissions->method('obligationOf')->willReturn([
            'id' => 900,
            'status' => 'open',
            'row_version' => 1,
            'agenda_code' => $agendaCode,
            'subject_type' => 'employer',
            'subject_reference' => '1',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
        ]);

        return $submissions;
    }

    /** @param list<mixed> $queue */
    private function vrep(array $queue): JmhzVrepClient
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));
        $stack->push(Middleware::tap(function (): void {
            $this->log[] = 'http';
        }));

        return new JmhzVrepClient(
            new Client(['handler' => $stack, 'http_errors' => false]),
            'test',
        );
    }

    /** @return MockObject&PayrollSubmissionTransportAttemptRepository */
    private function attempts(): MockObject
    {
        return $this->createMock(PayrollSubmissionTransportAttemptRepository::class);
    }

    /**
     * Ledger, který pokus otevře ve stavu `prepared` — výchozí stav pro
     * všechny scénáře, kde obálka opravdu odchází.
     *
     * @return MockObject&PayrollSubmissionTransportAttemptRepository
     */
    private function openedAttempts(): MockObject
    {
        $attempts = $this->attempts();
        $attempts->method('nextAttemptNo')->willReturn(1);
        $attempts->expects(self::once())->method('open')->willReturn(self::attemptRow());

        return $attempts;
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function attemptRow(array $overrides = []): array
    {
        return array_merge([
            'id' => self::ATTEMPT,
            'supplier_id' => self::SUPPLIER,
            'environment' => 'test',
            'submission_id' => self::SUBMISSION,
            'channel' => JmhzDispatchService::CHANNEL,
            'attempt_no' => 1,
            'status' => 'prepared',
            'correlation_reference' => null,
            'request_sha256' => str_repeat('a', 64),
            'response_http_status' => null,
            'error_code' => null,
            'error_message' => null,
            'next_retry_at' => null,
            'poll_count' => 0,
            'last_polled_at' => null,
            'last_poll_error' => null,
            'sent_at' => null,
            'completed_at' => null,
            'closed_at' => null,
            'close_attempts' => 0,
            'close_error' => null,
            'row_version' => 0,
            'created_by' => 3,
        ], $overrides);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function sentRow(array $overrides = []): array
    {
        return self::attemptRow(array_merge([
            'status' => 'awaiting_protocol',
            'correlation_reference' => self::CORRELATION,
            'response_http_status' => 200,
            'sent_at' => '2026-04-11 08:00:00',
            'row_version' => 1,
        ], $overrides));
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function completedRow(array $overrides = []): array
    {
        return self::sentRow(array_merge([
            'status' => 'completed',
            'completed_at' => '2026-04-11 08:30:00',
            'poll_count' => 1,
            'last_polled_at' => '2026-04-11 08:30:00',
            'row_version' => 2,
        ], $overrides));
    }

    private function text(\DOMXPath $xpath, string $expression): string
    {
        $node = $xpath->query($expression)->item(0);

        return $node === null ? '' : trim($node->textContent);
    }

    /** Zkrácená, ale doslovná odpověď testovacího VREP na odeslané podání. */
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

    /** @return array{cert:string,pfx:string,password:string} */
    private static function certificate(): array
    {
        static $material = null;
        if (is_array($material)) {
            return $material;
        }
        // OpenSSL na Windows nemusí mít openssl.cnf na očekávaném místě a bez
        // něj klíč nevyrobí. Test si proto nese vlastní minimální konfiguraci.
        $options = ['config' => self::opensslConfig()];
        $key = openssl_pkey_new($options + [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key, self::opensslErrors());
        $csr = openssl_csr_new(
            ['commonName' => 'JMHZ Dispatch Test', 'countryName' => 'CZ'],
            $key,
            $options + ['digest_alg' => 'sha256'],
        );
        self::assertNotFalse($csr, self::opensslErrors());
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options + ['digest_alg' => 'sha256']);
        self::assertNotFalse($certificate, self::opensslErrors());
        openssl_x509_export($certificate, $pem);
        $password = 'jmhz-test';
        self::assertTrue(
            openssl_pkcs12_export($certificate, $pfx, $key, $password),
            self::opensslErrors(),
        );

        return $material = [
            'cert' => (string) $pem,
            'pfx' => (string) $pfx,
            'password' => $password,
        ];
    }

    private static function opensslConfig(): string
    {
        static $path = null;
        if (is_string($path)) {
            return $path;
        }
        $file = tempnam(sys_get_temp_dir(), 'jmhz-openssl-');
        self::assertIsString($file);
        file_put_contents($file, "[req]\ndistinguished_name = dn\n[dn]\n[v3_ca]\n");
        register_shutdown_function(static function () use ($file): void {
            if (is_file($file)) {
                unlink($file);
            }
        });

        return $path = $file;
    }

    private static function opensslErrors(): string
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return $errors === [] ? 'OpenSSL nehlásí chybu.' : implode('; ', $errors);
    }
}
