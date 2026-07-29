<?php

declare(strict_types=1);

namespace MyInvoice\Action\Ai;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Ai\AiSuggestionService;
use MyInvoice\Service\Ai\AiProviderHttpClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BankAiSuggestionAction
{
    public function __construct(
        private readonly AiSuggestionService $service,
        private readonly AiProviderHttpClient $provider,
    ) {}

    public function availability(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'bank.post', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění účtovat banku.', 403);
        }
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        return Json::ok($response, [
            'available' => $this->service->scopeEnabled($supplierId, 'bank_tx')
                && $this->provider->isClassificationAvailable($supplierId),
        ]);
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'bank.post', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění účtovat banku.', 403);
        }
        $query = trim((string) (((array) ($request->getParsedBody() ?? []))['query'] ?? ''));
        if (mb_strlen($query) < 3 || mb_strlen($query) > 500) {
            return Json::error($response, 'validation_failed', 'Dotaz musí mít 3 až 500 znaků.', 422);
        }
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $result = $this->service->suggestBankNow($supplierId, (int) $args['id'], $query);
        if (($result['ok'] ?? false) !== true) {
            $code = (string) ($result['error'] ?? 'ai_unavailable');
            $status = $code === 'not_found' ? 404
                : ($code === 'invalid_accounts' ? 422
                    : (in_array($code, ['ai_disabled', 'dpa_not_confirmed', 'source_muted', 'residency_conflict', 'daily_limit'], true) ? 409 : 502));
            $message = $code === 'invalid_accounts'
                ? 'AI navrhlo kontaci, která neodpovídá směru transakce nebo účetní osnově.'
                : 'AI návrh teď není dostupný.';
            return Json::error($response, $code, $message, $status);
        }
        $item = (array) ($result['suggestion'] ?? []);
        return Json::ok($response, [
            'suggestion_id' => (int) ($item['id'] ?? 0),
            'debit_account_code' => (string) ($item['debit_account_code'] ?? ''),
            'credit_account_code' => (string) ($item['credit_account_code'] ?? ''),
            'reasoning' => (string) ($item['ai_reasoning'] ?? ''),
            'confidence' => (float) ($item['confidence'] ?? 0),
        ]);
    }
}
