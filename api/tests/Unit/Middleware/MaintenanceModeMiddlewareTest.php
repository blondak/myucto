<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\MaintenanceModeMiddleware;
use MyInvoice\Service\System\MaintenanceLock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * H-03 — zámek údržby. Kontrakt je dohodnutý s provozovatelem hostingu:
 * 503 s `Retry-After` na všechno kromě `/api/health`, čitelná stránka pro
 * člověka a JSON pro API klienta, a odstranění souboru = okamžitý konec.
 */
final class MaintenanceModeMiddlewareTest extends TestCase
{
    private string $dir;
    private string $file;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/myucto-maintenance-mw-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
        $this->file = $this->dir . '/maintenance.lock';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @rmdir($this->dir);
    }

    private function middleware(int $retryAfter = 300): MaintenanceModeMiddleware
    {
        return new MaintenanceModeMiddleware(
            new MaintenanceLock(new Config([
                'maintenance' => ['lock_file' => $this->file, 'retry_after' => $retryAfter],
            ])),
            new ResponseFactory(),
        );
    }

    private function request(string $method, string $path, string $accept = ''): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'https://instance.example.test' . $path);

        return $accept === '' ? $request : $request->withHeader('Accept', $accept);
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }

    public function testWithoutTheLockNothingChanges(): void
    {
        $response = $this->middleware()->process(
            $this->request('GET', '/api/invoices'),
            $this->okHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Retry-After'));
    }

    public function testApiClientGetsJsonWithRetryAfter(): void
    {
        touch($this->file);

        $response = $this->middleware(420)->process(
            $this->request('GET', '/api/invoices', 'application/json'),
            $this->okHandler(),
        );
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('420', $response->getHeaderLine('Retry-After'));
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('maintenance', $body['error']['code'] ?? null);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * Past se SPA: frontend chodí přes `api/public/index.php`, takže požadavek
     * na `/` nebo na klientskou routu musí skončit ČITELNOU stránkou, ne bílým
     * oknem ani 500.
     */
    public function testBrowserGetsReadableHtmlPage(): void
    {
        touch($this->file);

        $response = $this->middleware()->process(
            $this->request('GET', '/invoices/17', 'text/html,application/xhtml+xml,*/*;q=0.8'),
            $this->okHandler(),
        );
        $html = (string) $response->getBody();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('300', $response->getHeaderLine('Retry-After'));
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<html', $html);
        self::assertStringContainsString('údržba', $html);
        self::assertStringNotContainsString('{"error"', $html);
    }

    /** `Accept: * / *` (curl bez hlaviček) na neAPI cestě je požadavek na stránku. */
    public function testWildcardAcceptOnPageRouteGetsHtml(): void
    {
        touch($this->file);

        $response = $this->middleware()->process(
            $this->request('GET', '/', '*/*'),
            $this->okHandler(),
        );

        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    }

    /**
     * Health MUSÍ zůstat dostupný — jinak hosting nepozná rozdíl mezi
     * plánovanou údržbou a výpadkem instance.
     */
    public function testHealthStaysReachableDuringMaintenance(): void
    {
        touch($this->file);

        foreach ([['GET', 204], ['HEAD', 204], ['POST', 503]] as [$method, $expected]) {
            $response = $this->middleware()->process(
                $this->request($method, '/api/health'),
                $this->okHandler(),
            );

            self::assertSame($expected, $response->getStatusCode(), $method . ' /api/health');
        }
    }

    /** Výjimka je PŘESNÁ shoda — prefix ani podcesty ji nedostávají. */
    public function testOnlyTheExactHealthPathIsExempt(): void
    {
        touch($this->file);

        foreach (['/api/health/', '/api/health/details', '/api/healthcheck'] as $path) {
            $response = $this->middleware()->process(
                $this->request('GET', $path),
                $this->okHandler(),
            );

            self::assertSame(503, $response->getStatusCode(), $path);
        }
    }

    public function testRemovingTheLockEndsMaintenanceWithoutAnyRestart(): void
    {
        $middleware = $this->middleware();
        touch($this->file);
        self::assertSame(
            503,
            $middleware->process($this->request('GET', '/api/invoices'), $this->okHandler())->getStatusCode(),
        );

        unlink($this->file);

        self::assertSame(
            204,
            $middleware->process($this->request('GET', '/api/invoices'), $this->okHandler())->getStatusCode(),
            'Žádná cache nesmí údržbu přežít — odstranění souboru ji ukončuje okamžitě.',
        );
    }

    public function testOperatorReasonReachesTheUserEscaped(): void
    {
        file_put_contents($this->file, json_encode([
            'reason' => 'Upgrade <b>DB</b>',
        ], JSON_UNESCAPED_UNICODE));

        $html = (string) $this->middleware()->process(
            $this->request('GET', '/', 'text/html'),
            $this->okHandler(),
        )->getBody();

        self::assertStringContainsString('Upgrade &lt;b&gt;DB&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>DB</b>', $html);
    }
}
