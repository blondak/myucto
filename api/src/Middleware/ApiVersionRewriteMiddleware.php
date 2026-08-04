<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Http\RequestPath;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Veřejná verze API se servíruje pod `/api/v1/...`. Interní SPA volá `/api/...`
 * a zatím se neměnili tisíce front-end volání. Tento middleware proto přepíše
 * URI z `/api/v1/...` na `/api/...` ještě před routerem — oba prefixy sdílejí
 * stejné handlery.
 *
 * Běží jako outermost (před AuthMiddleware), aby všechny ostatní MW včetně
 * AuthMiddleware::PUBLIC_PATHS pracovaly už s přepsanou cestou.
 *
 * Response dostane hlavičku `X-API-Version: 1`, aby integrátoři mohli ověřit,
 * že kvičí správný endpoint a my zachytili pokus o starší verzi.
 */
final class ApiVersionRewriteMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $uri = $request->getUri();
        $path = $uri->getPath();

        // Denylist se testuje na normalizované cestě: `Uri::filterPath()` percent-encoding
        // zachovává, router ho před dispatchem dekóduje, takže `/api/v1/auth/%77ebauthn/…`
        // by jinak 404 minulo. Přepis níže naopak zůstává nad syrovou cestou, aby se
        // encoding předával dál beze změny a dekódovalo se právě jednou, až v cíli.
        if (preg_match('#^/api/v1/auth/(webauthn|mfa|session)(/|$)#', RequestPath::normalize($path)) === 1) {
            return Json::error(
                new SlimResponse(404),
                'not_found',
                'Tento interní endpoint není součástí veřejného API.',
                404,
            )->withHeader('X-API-Version', '1');
        }

        if (str_starts_with($path, '/api/v1/') || $path === '/api/v1') {
            $newPath = $path === '/api/v1' ? '/api' : '/api' . substr($path, 7);
            $request = $request->withUri($uri->withPath($newPath));
        }

        $response = $handler->handle($request);

        if (str_starts_with($path, '/api/')) {
            $response = $response->withHeader('X-API-Version', '1');
        }
        return $response;
    }
}
