<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Epo;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Service\Epo\EpoClient;
use MyInvoice\Service\Epo\EpoException;
use PHPUnit\Framework\TestCase;

final class EpoClientTest extends TestCase
{
    public function testHandoffClientHasNoSandboxEnvironmentSwitch(): void
    {
        $constructor = new \ReflectionMethod(EpoClient::class, '__construct');

        self::assertSame(1, $constructor->getNumberOfParameters());
    }

    public function testReturnsOnlyValidatedOfficialHandoffUrl(): void
    {
        $client = $this->clientWith(new Response(
            200,
            ['Content-Type' => 'application/xml'],
            '<?xml version="1.0"?><Odpoved><Url>https://adisspr.mfcr.cz/dpr/idpr_epo/epo2/formular?x=abc</Url></Odpoved>',
        ));

        $result = $client->createHandoff('<?xml version="1.0"?><Pisemnost/>');

        self::assertSame(200, $result['http_status']);
        self::assertSame(
            'https://adisspr.mfcr.cz/dpr/idpr_epo/epo2/formular?x=abc',
            $result['url'],
        );
        self::assertNotSame('', $result['expires_at']);
    }

    public function testRejectsUrlOutsideOfficialHost(): void
    {
        $client = $this->clientWith(new Response(
            200,
            ['Content-Type' => 'application/xml'],
            '<Odpoved><Url>https://attacker.example/steal</Url></Odpoved>',
        ));

        $this->expectException(EpoException::class);
        $this->expectExceptionMessage('neplatný odkaz');
        $client->createHandoff('<Pisemnost/>');
    }

    public function testSurfacesSanitizedEpoValidationErrors(): void
    {
        $client = $this->clientWith(new Response(
            200,
            ['Content-Type' => 'application/xml'],
            '<Chyby><Chyba Typ="K"><Text>Chybí povinná věta.</Text></Chyba></Chyby>',
        ));

        try {
            $client->createHandoff('<Pisemnost/>');
            self::fail('EPO rejection expected.');
        } catch (EpoException $e) {
            self::assertSame('epo_rejected', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
            self::assertStringContainsString('Chybí povinná věta.', $e->getMessage());
        }
    }

    public function testConnectionFailureIsReportedAsTemporaryOutage(): void
    {
        $handler = HandlerStack::create(new MockHandler([
            new ConnectException('Synthetic timeout', new Request('POST', 'https://adisspr.mfcr.cz')),
        ]));
        $client = new EpoClient(new Client(['handler' => $handler, 'http_errors' => false]));

        try {
            $client->createHandoff('<Pisemnost/>');
            self::fail('Connection failure expected.');
        } catch (EpoException $e) {
            self::assertSame('epo_unavailable', $e->errorCode);
            self::assertSame(503, $e->httpStatus);
        }
    }

    public function testHandoffAlwaysUsesProductionFormEndpoint(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(
                200,
                ['Content-Type' => 'application/xml'],
                '<Odpoved><Url>https://adisspr.mfcr.cz/dpr/idpr_epo/epo2/formular?x=form</Url></Odpoved>',
            ),
        ]));
        $handler->push(Middleware::history($history));
        $client = new EpoClient(new Client([
            'handler' => $handler,
            'http_errors' => false,
        ]));

        $result = $client->createHandoff('<Pisemnost/>');

        self::assertSame('production', $client->environment());
        self::assertSame(
            'https://adisspr.mfcr.cz/dpr/epo_podani?otevriFormular=1',
            (string) $history[0]['request']->getUri(),
        );
        self::assertSame(
            'https://adisspr.mfcr.cz/dpr/idpr_epo/epo2/formular?x=form',
            $result['url'],
        );
    }

    private function clientWith(Response $response): EpoClient
    {
        $handler = HandlerStack::create(new MockHandler([$response]));
        return new EpoClient(new Client(['handler' => $handler, 'http_errors' => false]));
    }
}
