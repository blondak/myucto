<?php

declare(strict_types=1);

namespace MyInvoice\Action\Portfolio;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Portfolio\PortfolioAggregationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Přehled firem pro účetní kancelář (cross-supplier dashboard, Fáze F,
 * audit 2026-07 P2/M). Na rozdíl od zbytku appky NENÍ scoped na X-Supplier-Id —
 * agreguje přes všechny firmy uživatele (viz PortfolioAggregationService).
 * Role 'client' sem nemá přístup (PermissionMiddleware).
 */
final class PortfolioAction
{
    public function __construct(
        private readonly PortfolioAggregationService $portfolio,
    ) {}

    public function overview(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        return Json::ok($response, $this->portfolio->overview(
            $userId,
            RequestAuthorization::isSuperadmin($request),
            new \DateTimeImmutable(),
        ));
    }
}
