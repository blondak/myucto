<?php

declare(strict_types=1);

namespace MyInvoice\Http;

/**
 * Normalizace cesty requestu pro middleware, která se podle ní ROZHODUJÍ.
 *
 * Middleware běží PŘED RoutingMiddleware. `Slim\Psr7\Uri::filterPath()`
 * percent-encoding ZACHOVÁVÁ, kdežto `Slim\Routing\RouteResolver` dělá před
 * dispatchem `rawurldecode()`. Jeden `%XX` v URL tedy rozejde dvě vrstvy:
 * middleware mine své konkrétní pravidlo, router doručí tutéž Action.
 * `POST /api/auth/%66orgot` proto běželo bez rate limitu a
 * `POST /api/purchase-invoices/%70ayment-orders` spadlo v ACL na hrubší
 * modulový catch-all (permission downgrade).
 *
 * Dekódujeme PRÁVĚ JEDNOU, stejně jako router — vícenásobné dekódování je
 * samo o sobě zranitelnost (`%252f` by se při druhém průchodu stalo `/`
 * a rozsekalo cestu na segmenty, které router nikdy neviděl). Volající proto
 * normalizuje na JEDNOM místě a výsledek si předává dál; nikdy nedávej
 * `normalize()` na hodnotu, která už jím prošla.
 *
 * Navíc srovnáme vícenásobná lomítka a `.` / `..` segmenty. Router tohle
 * nedělá, takže jsme tu striktnější než dispatcher: v nejhorším případě
 * uplatníme pravidlo na cestu, kterou router stejně odmítne 404 — fail-closed.
 *
 * Pro LOGOVÁNÍ se nepoužívá — do logu patří to, co klient reálně poslal.
 */
final class RequestPath
{
    public static function normalize(string $path): string
    {
        $decoded = rawurldecode($path);

        // Null byte v cestě není nikdy legitimní; ať neuřízne porovnání.
        $decoded = str_replace("\0", '', $decoded);

        $trailingSlash = $decoded !== '/' && str_ends_with($decoded, '/');

        $out = [];
        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        $normalized = '/' . implode('/', $out);
        if ($trailingSlash && $normalized !== '/') {
            $normalized .= '/';
        }

        return $normalized;
    }
}
