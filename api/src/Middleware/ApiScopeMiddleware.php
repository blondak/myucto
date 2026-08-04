<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Http\RequestPath;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Enforce token scopes + path allowlist pro bearer-authed requesty.
 *
 * 1) Path allowlist — bearer token smí volat JEN dokumentovaný veřejný subset
 *    (`/api/v1/*`, po přepisu `ApiVersionRewriteMiddleware` = `/api/*`). Interní
 *    plocha (`/api/admin/*`, správa tokenů, login/totp/change-password, citlivá
 *    nastavení signing/email-branding/IMAP) je pro token nedostupná, i kdyby ho
 *    vytvořil admin. Tím se uniklý token nedostane mimo veřejné API.
 * 2) Scope:
 *      GET / HEAD                 → vyžaduje `read` (každý token splňuje)
 *      POST / PUT / PATCH / DELETE → vyžaduje `read_write`
 *    Nad rámec toho je účetní a daňová vrstva ({@see self::BEARER_READ_ONLY})
 *    pro token jednosměrná — zápis odmítne i `read_write`.
 *
 * Session auth (browser SPA) tímto MW není dotčen — uživatel má plná práva své role.
 *
 * Běží AŽ PO AuthMiddleware (potřebuje načtený api_token attribute).
 */
final class ApiScopeMiddleware implements MiddlewareInterface
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Whitelist veřejných API cest dostupných přes bearer token. Zrcadlí
     * `openapi.yaml` na úrovni zdrojových skupin. Cokoliv, co sem nepasuje
     * (zejména `/api/admin/*`, `/api/auth/*` kromě `api-me`, a interní
     * `/api/settings/*` mimo číselníky), je pro token zakázané → 403.
     *
     * Pozn.: cesty jsou už přepsané z `/api/v1/...` na `/api/...` (viz
     * ApiVersionRewriteMiddleware, běží jako outermost).
     *
     * @var list<string>
     */
    private const BEARER_ALLOWED = [
        // Systémové + dokumentace + connection-test
        '#^/api/health$#',
        '#^/api/version$#',
        '#^/api/openapi\.yaml$#',
        '#^/api/docs$#',
        '#^/api/reference$#',
        '#^/api/scalar$#',
        '#^/api/auth/api-me$#',
        // Byznys zdroje
        '#^/api/clients(/|$)#',
        '#^/api/projects(/|$)#',
        '#^/api/invoices(/|$)#',
        '#^/api/purchase-invoices(/|$)#',
        '#^/api/recurring(/|$)#',
        '#^/api/bank-statements(/|$)#',
        '#^/api/bank-transactions(/|$)#',
        '#^/api/bank-match-suggestions(/|$)#',
        '#^/api/logbook(/|$)#',
        '#^/api/documents(/|$)#',
        '#^/api/document-folders(/|$)#',
        '#^/api/suppliers(/|$)#',
        // Sklad a e-shop — MCP server nad nimi staví nástroje pro zboží, ceny
        // a dostupnost. Moduly jsou opt-in (`stock_enabled`) a PermissionMiddleware
        // nad `^/api/(stock|eshop)` platí dál, takže se tím nic neotevírá plošně.
        '#^/api/stock(/|$)#',
        '#^/api/eshop(/|$)#',
        '#^/api/price-list-items(/|$)#',
        // Dashboard / CRM / reporty (čtení + exporty)
        '#^/api/dashboard(/|$)#',
        '#^/api/crm(/|$)#',
        '#^/api/portfolio(/|$)#',
        '#^/api/reports(/|$)#',
        '#^/api/tax(/|$)#',
        '#^/api/tax-evidence(/|$)#',
        '#^/api/accounting(/|$)#',
        '#^/api/search$#',
        '#^/api/branding-profiles$#',
        // Číselníky
        '#^/api/codebooks(/|$)#',
        '#^/api/expense-categories(/|$)#',
        '#^/api/revenue-categories(/|$)#',
        '#^/api/vat-classifications(/|$)#',
        // Nastavení — JEN veřejný subset (supplier + číselníky), NE signing/
        // pdf-signing/email-branding/bank-email-notices.
        '#^/api/settings/supplier$#',
        '#^/api/settings/supplier/invoice-counter$#',
        '#^/api/settings/supplier/logo$#',
        '#^/api/settings/currencies(/|$)#',
        '#^/api/settings/vat-rates(/|$)#',
        '#^/api/settings/units(/|$)#',
        '#^/api/settings/countries(/|$)#',
        '#^/api/settings/nace-codes$#',
    ];

    /**
     * Cesty, které jsou přes bearer token dostupné VÝHRADNĚ KE ČTENÍ — i pro token
     * se scope `read_write`.
     *
     * Účetní a daňová vrstva je záměrně jednosměrná: zaúčtování, storno zápisu,
     * uzavření období, zaevidování opravy podle § 46 / § 74b nebo odeslání podání
     * na EPO jsou úkony s daňovou odpovědností. Chyba v nich znamená opravné
     * podání, takže je nesmí spustit integrace ani AI agent — dělá je člověk
     * v aplikaci, kde vidí kontext a potvrzuje krok.
     *
     * Čtení je naopak žádoucí: odhad DPH za období, obratovka, rozvaha, výsledovka
     * i saldo jsou přesně ta čísla, kvůli kterým se integrace staví.
     *
     * @var list<string>
     */
    private const BEARER_READ_ONLY = [
        '#^/api/accounting(/|$)#',
        '#^/api/reports(/|$)#',
        '#^/api/tax(/|$)#',
        '#^/api/tax-evidence(/|$)#',
    ];

    public function __construct(
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'bearer') {
            return $handler->handle($request);
        }

        // Allowlist i read-only denylist matchujeme na normalizované cestě (viz
        // RequestPath) — obojí musí platit přesně tam, kam router request doručí.
        $path   = RequestPath::normalize($request->getUri()->getPath());
        $method = strtoupper($request->getMethod());

        // 1) Path allowlist — token mimo veřejný subset → 403 (vrací se 403, ne 404,
        //    aby integrátor dostal jasný signál; samotná existence interních cest
        //    není tajemství — jsou zdokumentované jako session-only).
        if (!$this->isBearerAllowed($path)) {
            $response = $this->responseFactory->createResponse(403);
            return Json::error(
                $response,
                'token_endpoint_forbidden',
                'API token má přístup pouze k veřejnému API (/api/v1). Tento endpoint je dostupný jen z webového rozhraní.',
                403,
            );
        }

        // 2) Scope
        if (in_array($method, self::READ_METHODS, true)) {
            return $handler->handle($request);
        }

        // 2a) Účetní / daňová vrstva — zápis přes token nikdy, ani se `read_write`.
        //     Kontrola je PŘED scope kontrolou schválně: token s právem zápisu by
        //     jinak prošel a hláška „nemáte scope" by lhala o důvodu odmítnutí.
        if ($this->isReadOnlyForBearer($path)) {
            $response = $this->responseFactory->createResponse(403);
            return Json::error(
                $response,
                'token_write_forbidden',
                'Účetní a daňová vrstva je přes API token dostupná pouze ke čtení. '
                . 'Zaúčtování, uzávěrku a daňová podání lze provést jen z webového rozhraní.',
                403,
            );
        }

        $apiToken = (array) $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN, []);
        $scope    = (string) ($apiToken['scope'] ?? '');

        if ($scope !== 'read_write') {
            $response = $this->responseFactory->createResponse(403);
            return Json::error(
                $response,
                'insufficient_scope',
                'API token nemá scope `read_write` pro tuto operaci.',
                403,
            );
        }

        return $handler->handle($request);
    }

    private function isBearerAllowed(string $path): bool
    {
        foreach (self::BEARER_ALLOWED as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }
        return false;
    }

    private function isReadOnlyForBearer(string $path): bool
    {
        foreach (self::BEARER_READ_ONLY as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }
        return false;
    }
}
