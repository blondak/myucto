<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Http\MaintenanceResponse;
use MyInvoice\Http\RequestPath;
use MyInvoice\Service\System\MaintenanceLock;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Zámek údržby spravované instalace (H-03) — hlavní, autoritativní vrstva.
 *
 * Existuje-li {@see MaintenanceLock}, vrací web 503 s hlavičkou `Retry-After`
 * na VŠECHNO kromě read-only `/api/health`.
 *
 * Proč je výjimka právě health a jen ta: hosting jinak nepozná rozdíl mezi
 * plánovanou údržbou a výpadkem instance. Health přitom sám hlásí `maintenance`
 * i počet běžících úloh, takže je to zároveň jediný kanál, přes který provozovatel
 * zjistí, kdy je bezpečné nasazovat.
 *
 * Pořadí v pipeline je součástí kontraktu (viz `Bootstrap::buildApp()`):
 *   - PŘED autentizací — 503 musí dostat i nepřihlášený,
 *   - UVNITŘ {@see ApiVersionRewriteMiddleware} — jinak by `/api/v1/health`
 *     dostalo 503, protože bypass se testuje na už přepsané cestě.
 *
 * Zámek se čte při každém requestu znovu; jeho odstranění tedy ukončí údržbu
 * okamžitě, bez restartu čehokoli. `.htaccess`/`web.config` mají ekvivalentní
 * pravidlo jen jako zrychlení — zámek leží mimo docroot, takže je to vrstva
 * navíc, nikdy ne bezpečnostní hranice.
 */
final class MaintenanceModeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly MaintenanceLock $lock,
        private readonly ResponseFactory $responses,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $path = RequestPath::normalize($request->getUri()->getPath());

        if (self::isReadOnlyHealth($request->getMethod(), $path)) {
            return $handler->handle($request);
        }

        if (!$this->lock->isActive()) {
            return $handler->handle($request);
        }

        return $this->maintenanceResponse($request, $path);
    }

    /** Přesná shoda, stejně jako v {@see TenantDomainMiddleware} — žádné prefixy. */
    public static function isReadOnlyHealth(string $method, string $normalizedPath): bool
    {
        return $normalizedPath === '/api/health'
            && in_array(strtoupper($method), ['GET', 'HEAD'], true);
    }

    private function maintenanceResponse(Request $request, string $path): Response
    {
        $response = $this->responses->createResponse(503)
            ->withHeader('Retry-After', (string) $this->lock->retryAfter());
        $message = $this->lock->message();

        if (MaintenanceResponse::wantsJson($path, $request->getHeaderLine('Accept'))) {
            return Json::error($response, MaintenanceResponse::CODE, $message, 503);
        }

        $response->getBody()->write(
            MaintenanceResponse::html($message, $this->lock->retryAfter())
        );

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
