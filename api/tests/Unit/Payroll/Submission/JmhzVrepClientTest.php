<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzGovTalkEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzVrepClient;
use PHPUnit\Framework\TestCase;

final class JmhzVrepClientTest extends TestCase
{
    /** @var list<array<string,mixed>> */
    private array $history = [];

    /** @param list<mixed> $queue */
    private function client(array $queue, string $environment = 'test'): JmhzVrepClient
    {
        $this->history = [];
        $handler = HandlerStack::create(new MockHandler($queue));
        $handler->push(Middleware::history($this->history));

        return new JmhzVrepClient(
            new Client(['handler' => $handler, 'http_errors' => false]),
            $environment,
        );
    }

    public function testSubmitPostsTheEnvelopeToTheVerifiedTestEndpoint(): void
    {
        $client = $this->client([
            new Response(200, ['Content-Type' => 'text/xml'], '<GovTalkMessage/>'),
        ]);

        $result = $client->submit('<GovTalkMessage/>');

        self::assertSame(200, $result->httpStatus);
        self::assertSame('text/xml', $result->contentType);
        self::assertSame(hash('sha256', '<GovTalkMessage/>'), $result->sha256());
        self::assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        self::assertSame(
            'https://t-epodani.cssz.cz/VREP/submission',
            (string) $request->getUri(),
        );
        self::assertSame('text/xml; charset=utf-8', $request->getHeaderLine('Content-Type'));
        self::assertSame('<GovTalkMessage/>', (string) $request->getBody());
    }

    /**
     * Produkční adresy jsou doložené (protokol ČSSZ v1.47, str. 47) a musí být
     * PŘESNĚ tyhle. Test je tu proto, aby se nedaly tiše změnit: rozdíl proti
     * testovacímu prostředí je jediný prefix `t-`, takže překlep je pravopisně
     * neviditelný a odešel by ostré podání na nesprávný cíl.
     */
    public function testProductionSubmissionGoesToDocumentedEndpoint(): void
    {
        $client = $this->client([
            new Response(200, ['Content-Type' => 'text/xml'], '<GovTalkMessage/>'),
        ], 'production');

        $client->submit('<GovTalkMessage/>');

        self::assertSame(
            'https://epodani.cssz.cz/VREP/submission',
            (string) $this->history[0]['request']->getUri(),
        );
    }

    public function testProductionPollGoesToDocumentedEndpoint(): void
    {
        $client = $this->client([
            new Response(200, ['Content-Type' => 'text/xml'], '<GovTalkMessage/>'),
        ], 'production');

        $client->poll('CID0000000001', '<GovTalkMessage>CID0000000001</GovTalkMessage>');

        self::assertSame(
            'https://epodani.cssz.cz/VREP/poll',
            (string) $this->history[0]['request']->getUri(),
        );
    }

    /** Prostředí se nesmějí prolnout — prefix `t-` je jediný rozdíl. */
    public function testEnvironmentsDoNotShareHost(): void
    {
        $test = $this->client([
            new Response(200, ['Content-Type' => 'text/xml'], '<GovTalkMessage/>'),
        ]);
        $test->submit('<GovTalkMessage/>');

        self::assertStringStartsWith(
            'https://t-epodani.cssz.cz/',
            (string) $this->history[0]['request']->getUri(),
        );
    }

    public function testHttpErrorIsMappedWithRemoteStatus(): void
    {
        $client = $this->client([
            new Response(503, ['Content-Type' => 'text/html'], 'nedostupné'),
        ]);

        try {
            $client->submit('<GovTalkMessage/>');
            self::fail('Chyba HTTP musí skončit výjimkou.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_vrep_http_error', $e->errorCode);
            self::assertSame(503, $e->remoteHttpStatus);
        }
    }

    public function testEmptyResponseIsRefused(): void
    {
        $client = $this->client([
            new Response(200, ['Content-Type' => 'text/xml'], ''),
        ]);

        try {
            $client->submit('<GovTalkMessage/>');
            self::fail('Prázdná odpověď musí skončit výjimkou.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_vrep_invalid_response', $e->errorCode);
        }
    }

    public function testTransportFailureIsMappedToUnavailable(): void
    {
        $client = $this->client([
            new ConnectException('spojení selhalo', new Request('POST', 'https://example.invalid')),
        ]);

        try {
            $client->submit('<GovTalkMessage/>');
            self::fail('Selhání spojení musí skončit výjimkou.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_vrep_unavailable', $e->errorCode);
        }
    }

    public function testEmptyEnvelopeNeverLeavesTheProcess(): void
    {
        $client = $this->client([]);

        try {
            $client->submit('   ');
            self::fail('Prázdná obálka se odeslat nesmí.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_vrep_request_empty', $e->errorCode);
        }
        self::assertSame([], $this->history);
    }

    public function testPollWithoutADeclaredRequestShapeRefusesToInventOne(): void
    {
        $client = $this->client([]);

        try {
            $client->poll('CID0000000001');
            self::fail('Poll bez doloženého tvaru musí skončit výjimkou.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_govtalk_shape_unverified', $e->errorCode);
        }
        self::assertSame([], $this->history);
    }

    public function testPollPostsTheSuppliedEnvelopeToThePollEndpoint(): void
    {
        $client = $this->client([
            new Response(200, ['Content-Type' => 'text/xml'], '<GovTalkMessage/>'),
        ]);
        $request = (new JmhzGovTalkEnvelope(JmhzTransportSample::shape()))->pollRequest(
            'CID0000000001',
            JmhzTransportSample::VARIABLE_SYMBOL,
            'CSSZ_JMHZ',
        );

        $result = $client->poll('CID0000000001', $request);

        self::assertSame('CID0000000001', $result->correlationId);
        self::assertSame(
            'https://t-epodani.cssz.cz/VREP/poll',
            (string) $this->history[0]['request']->getUri(),
        );
        self::assertSame($request, (string) $this->history[0]['request']->getBody());
    }

    public function testPollRefusesAnEnvelopeForAnotherSubmission(): void
    {
        $client = $this->client([]);

        try {
            $client->poll('CID0000000001', '<GovTalkMessage>CID0000000002</GovTalkMessage>');
            self::fail('Cizí poll obálka musí skončit výjimkou.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_vrep_poll_request_mismatch', $e->errorCode);
        }
        self::assertSame([], $this->history);
    }

    public function testUnknownEnvironmentIsRefusedInConstructor(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new JmhzVrepClient(null, 'sandbox');
    }
}
