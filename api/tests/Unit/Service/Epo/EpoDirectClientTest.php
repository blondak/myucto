<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Epo;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Service\Epo\EpoDirectClient;
use PHPUnit\Framework\TestCase;

final class EpoDirectClientTest extends TestCase
{
    public function testTestModePostsUntouchedPkcs7BytesToOfficialEndpoint(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/xml'], '<Chyby/>'),
        ]));
        $handler->push(Middleware::history($history));
        $client = new EpoDirectClient(new Client([
            'handler' => $handler,
            'http_errors' => false,
        ]));
        $signed = random_bytes(128);

        $response = $client->submit($signed, true);

        self::assertSame(200, $response['http_status']);
        self::assertCount(1, $history);
        $request = $history[0]['request'];
        self::assertSame('https://adisspr.mfcr.cz/dpr/epo_podani?test=1', (string) $request->getUri());
        self::assertSame('application/pkcs7-signature', $request->getHeaderLine('Content-Type'));
        self::assertSame($signed, (string) $request->getBody());
    }

    public function testStatusUsesDocumentedFormFields(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/xml'], '<Stav/>'),
        ]));
        $handler->push(Middleware::history($history));
        $client = new EpoDirectClient(new Client([
            'handler' => $handler,
            'http_errors' => false,
        ]));

        $client->status('123456', 'state-secret');

        $request = $history[0]['request'];
        self::assertSame('https://adisspr.mfcr.cz/dpr/epo_stav', (string) $request->getUri());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('C=123456&H=state-secret', (string) $request->getBody());
    }

    public function testSandboxEnvironmentRoutesEntireLifecycleToSandboxHost(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/xml'], '<Chyby/>'),
            new Response(200, ['Content-Type' => 'application/xml'], '<Stav/>'),
            new Response(200, ['Content-Type' => 'application/xml'], '<Prijeti/>'),
        ]));
        $handler->push(Middleware::history($history));
        $client = new EpoDirectClient(new Client([
            'handler' => $handler,
            'http_errors' => false,
        ]), 'test');

        $client->submit('signed', true);
        $client->status('123456', 'state-secret');
        $client->receiveOffline('transfer', 'offline-secret');

        self::assertSame('test', $client->environment());
        self::assertSame(
            'https://zkus.mojedane.gov.cz/dpr/epo_podani?test=1',
            (string) $history[0]['request']->getUri(),
        );
        self::assertSame(
            'https://zkus.mojedane.gov.cz/dpr/epo_stav',
            (string) $history[1]['request']->getUri(),
        );
        self::assertSame(
            'https://zkus.mojedane.gov.cz/dpr/epo_prijeti',
            (string) $history[2]['request']->getUri(),
        );
    }

    public function testAttemptEnvironmentCanOverrideCurrentDefaultForStatusRecovery(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/xml'], '<Stav/>'),
        ]));
        $handler->push(Middleware::history($history));
        $client = new EpoDirectClient(new Client([
            'handler' => $handler,
            'http_errors' => false,
        ]), 'production');

        $client->status('123456', 'state-secret', 'test');

        self::assertSame(
            'https://zkus.mojedane.gov.cz/dpr/epo_stav',
            (string) $history[0]['request']->getUri(),
        );
    }
}
