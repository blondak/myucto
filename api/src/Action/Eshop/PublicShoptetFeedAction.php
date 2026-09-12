<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Shoptet\ShoptetFeedService;
use MyInvoice\Service\Shoptet\ShoptetSettingsService;
use MyInvoice\Service\Tenant\PublicTenantGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Veřejný XML feed zásob a cen pro automatický import produktů Shoptetu.
 *
 *   GET /api/public/shoptet/feed/{token}
 *
 * Bez přihlášení, jen token (32 náhodných bajtů, v DB SHA-256). Neznámý, vypnutý
 * i cizí token vrací stejné 404 — z odpovědi nejde poznat, jestli feed existoval.
 * Feed obsahuje jen kód, EAN, cenu s DPH a množství zvolených produktů jedné firmy.
 *
 * `ETag` a `Last-Modified` drží Shoptetu informaci, zda se soubor změnil (automatický
 * import od 10. 2. 2025 zpracuje jen změněný feed); `If-None-Match` vrací 304.
 * Rate limit podle IP řeší {@see \MyInvoice\Middleware\RateLimitMiddleware}.
 */
final class PublicShoptetFeedAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly ShoptetSettingsService $settings,
        private readonly ShoptetFeedService $feed,
        private readonly PublicTenantGuard $tenantGuard,
    ) {}

    /** @param array<string,string> $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $row = $this->settings->findByFeedToken((string) ($args['token'] ?? ''));
        if ($row === null) {
            return $this->notFound($response);
        }
        $supplierId = (int) $row['supplier_id'];
        if (!$this->tenantGuard->allows($request, $supplierId) || !$this->stockEnabled($supplierId)) {
            return $this->notFound($response);
        }

        $out = $this->feed->xml($supplierId, $row);
        $etag = '"' . $out['etag'] . '"';
        $changedAt = $this->settings->rememberFeedVersion($supplierId, $out['etag']);
        $lastModified = gmdate('D, d M Y H:i:s', (int) strtotime($changedAt)) . ' GMT';

        $response = $response
            ->withHeader('ETag', $etag)
            ->withHeader('Last-Modified', $lastModified)
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        $ifNoneMatch = trim($request->getHeaderLine('If-None-Match'));
        if ($ifNoneMatch !== '' && in_array($etag, array_map('trim', explode(',', $ifNoneMatch)), true)) {
            return $response->withStatus(304);
        }

        $response->getBody()->write($out['xml']);

        return $response->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    private function stockEnabled(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT stock_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);

        return (bool) $stmt->fetchColumn();
    }

    private function notFound(Response $response): Response
    {
        $response->getBody()->write('Not found');

        return $response->withStatus(404)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
