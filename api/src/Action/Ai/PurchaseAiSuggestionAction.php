<?php

declare(strict_types=1);

namespace MyInvoice\Action\Ai;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Ai\AiProviderHttpClient;
use MyInvoice\Service\Ai\AiSuggestionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * „Zeptat se AI na kontaci" u přijaté faktury — protějšek {@see BankAiSuggestionAction}
 * pro doklady.
 *
 *   POST /api/purchase-invoices/{id}/ai-suggest   { query }
 *   GET  /api/purchase-ai-suggestion-availability
 *
 * Klasifikace přijatých faktur běžela dosud jen na pozadí přes frontu: účetní stojící
 * nad konkrétním dokladem se nemohla zeptat a doplnit souvislost, kterou z faktury není
 * vidět („nájem serverovny", „školení, ne cestovné"). Právě tahle souvislost přitom
 * rozhoduje o nákladovém účtu.
 *
 * Vrací se JEN nákladový účet (MD). Protistrana je u přijaté faktury dána závazkem
 * vůči dodavateli, ne odhadem modelu — nechat AI určovat i tu by znamenalo pustit ji
 * k rozhodnutí, které z dokladu jednoznačně plyne.
 */
final class PurchaseAiSuggestionAction
{
    public function __construct(
        private readonly AiSuggestionService $service,
        private readonly AiProviderHttpClient $provider,
    ) {}

    public function availability(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění účtovat.', 403);
        }
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);

        return Json::ok($response, [
            'available' => $this->service->scopeEnabled($supplierId, 'purchase_invoices')
                && $this->provider->isClassificationAvailable($supplierId),
        ]);
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění účtovat.', 403);
        }
        $query = trim((string) (((array) ($request->getParsedBody() ?? []))['query'] ?? ''));
        if (mb_strlen($query) < 3 || mb_strlen($query) > 500) {
            return Json::error($response, 'validation_failed', 'Dotaz musí mít 3 až 500 znaků.', 422);
        }

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $result = $this->service->suggestPurchaseNow($supplierId, (int) $args['id'], $query);
        if (($result['ok'] ?? false) !== true) {
            $code = (string) ($result['error'] ?? 'ai_unavailable');
            $status = $code === 'not_found' ? 404
                : (in_array($code, ['ai_disabled', 'dpa_not_confirmed', 'source_muted', 'residency_conflict', 'daily_limit', 'stale_document'], true) ? 409 : 502);

            return Json::error($response, $code, 'AI návrh teď není dostupný.', $status);
        }

        $item = (array) ($result['suggestion'] ?? []);
        $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];

        return Json::ok($response, [
            'suggestion_id'      => (int) ($item['id'] ?? 0),
            'debit_account_code' => (string) ($payload['debit_account_code'] ?? ''),
            'reasoning'          => (string) ($item['reasoning'] ?? ''),
            'confidence'         => (float) ($item['confidence'] ?? 0),
        ]);
    }
}
