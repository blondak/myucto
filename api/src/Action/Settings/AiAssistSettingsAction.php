<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Ai\AiDpaGate;
use MyInvoice\Service\Ai\AiJobService;
use MyInvoice\Service\Ai\AiKillSwitchService;
use MyInvoice\Service\Ai\EmbeddingGatewayInterface;
use MyInvoice\Service\Ai\KnnSuggester;
use MyInvoice\Service\Import\LlmProviderRegistry;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AiAssistSettingsAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly LlmProviderRegistry $providers,
        private readonly EmbeddingGatewayInterface $embeddings,
        private readonly KnnSuggester $knn,
        private readonly AiDpaGate $dpa,
        private readonly AiKillSwitchService $killSwitch,
        private readonly AiJobService $jobs,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->state($this->supplierId($request)));
    }

    public function put(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'settings.ai_provider', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění měnit AI asistenci.', 403);
        }
        $supplierId = $this->supplierId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $scope = null;
        if (array_key_exists('scope', $body)) {
            $scope = array_values(array_unique(array_filter(array_map('strval', (array) $body['scope']),
                static fn (string $item): bool => in_array($item, ['bank_tx', 'purchase_invoices'], true))));
            if (count($scope) !== count((array) $body['scope'])) {
                return Json::error($response, 'validation_failed', 'Neplatný rozsah AI asistence.', 422);
            }
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT ai_provider,ai_assist_enabled,ai_assist_scope,ai_pseudo_salt,ai_dpa_confirmations FROM supplier WHERE id=? FOR UPDATE');
            $stmt->execute([$supplierId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $pdo->rollBack();
                return Json::error($response, 'not_found', 'Firma nebyla nalezena.', 404);
            }
            $provider = (string) ($row['ai_provider'] ?: 'anthropic');
            $confirmations = json_decode((string) ($row['ai_dpa_confirmations'] ?? '{}'), true);
            if (!is_array($confirmations)) {
                $confirmations = [];
            }
            if (isset($body['dpa_confirm']) && is_string($body['dpa_confirm'])) {
                if (!in_array($body['dpa_confirm'], ['anthropic', 'azure_openai', 'openai', 'gemini'], true)) {
                    throw new \InvalidArgumentException('validation_failed');
                }
                $confirmations[$body['dpa_confirm']] = ['confirmed_at' => gmdate('c'), 'user_id' => $userId];
            }
            if (isset($body['dpa_revoke']) && is_string($body['dpa_revoke'])) {
                unset($confirmations[$body['dpa_revoke']]);
            }
            $enabled = array_key_exists('enabled', $body) ? (bool) $body['enabled'] : (bool) $row['ai_assist_enabled'];
            if ($enabled && !is_string($confirmations[$provider]['confirmed_at'] ?? null)) {
                $pdo->rollBack();
                return Json::error($response, 'dpa_required', 'Bez potvrzení DPA nelze AI návrhy zapnout.', 409);
            }
            $salt = $row['ai_pseudo_salt'];
            if ($enabled && (!is_string($salt) || strlen($salt) !== 32)) {
                try {
                    $salt = random_bytes(32);
                } catch (\Throwable) {
                    $pdo->rollBack();
                    return Json::error($response, 'salt_generation_failed', 'Nepodařilo se vytvořit pseudonymizační klíč.', 409);
                }
            }
            $pdo->prepare(
                'UPDATE supplier SET ai_assist_enabled=?,ai_assist_scope=COALESCE(?,ai_assist_scope),ai_pseudo_salt=?,ai_dpa_confirmations=? WHERE id=?'
            )->execute([
                (int) $enabled, $scope === null ? null : implode(',', $scope), $salt,
                json_encode($confirmations, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $supplierId,
            ]);
            $pdo->commit();
        } catch (\InvalidArgumentException) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return Json::error($response, 'validation_failed', 'Neplatný poskytovatel.', 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        if (isset($body['unmute_source']) && in_array($body['unmute_source'], ['knn', 'llm'], true)) {
            $this->killSwitch->unmute($supplierId, (string) $body['unmute_source'], $userId);
        }
        if (!empty($enabled)) {
            $previousScope = array_values(array_filter(explode(',', (string) ($row['ai_assist_scope'] ?? ''))));
            $effectiveScope = $scope ?? $previousScope;
            if (in_array('bank_tx', $effectiveScope, true)
                && (empty($row['ai_assist_enabled']) || !in_array('bank_tx', $previousScope, true))) {
                $this->jobs->enqueue($supplierId, 'embed_backfill', 'bank_transaction', 0);
            }
            if (in_array('purchase_invoices', $effectiveScope, true)
                && (empty($row['ai_assist_enabled']) || !in_array('purchase_invoices', $previousScope, true))) {
                $this->jobs->enqueue($supplierId, 'embed_backfill', 'purchase_invoice', 0);
            }
        }
        return Json::ok($response, $this->state($supplierId));
    }

    /** @return array<string,mixed> */
    private function state(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ai_assist_enabled,ai_assist_scope,ai_provider,ai_data_region FROM supplier WHERE id=?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $provider = (string) (($row['ai_provider'] ?? null) ?: 'anthropic');
        try {
            $caps = $this->providers->resolve($provider)->capabilities($supplierId);
            $label = $caps->label;
            $region = $caps->dataRegion;
        } catch (\Throwable) {
            $label = $provider;
            $region = (string) ($row['ai_data_region'] ?? 'us');
        }
        $bankLabels = $this->knn->labelCount($supplierId, 'bank_transaction');
        $purchaseLabels = $this->knn->labelCount($supplierId, 'purchase_invoice');
        return [
            'enabled' => (bool) ($row['ai_assist_enabled'] ?? false),
            'scope' => array_values(array_filter(explode(',', (string) ($row['ai_assist_scope'] ?? '')))),
            'provider' => $provider, 'provider_label' => $label, 'data_region' => $region,
            'dpa_confirmed' => $this->dpa->confirmations($supplierId),
            'embedding_available' => $this->embeddings->isAvailable($supplierId),
            'knn_warm' => [
                'bank_transaction' => $bankLabels >= KnnSuggester::COLD_START_MIN_LABELS,
                'purchase_invoice' => $purchaseLabels >= KnnSuggester::COLD_START_MIN_LABELS,
                'labels' => ['bank_transaction' => $bankLabels, 'purchase_invoice' => $purchaseLabels],
            ],
            'muted_sources' => $this->killSwitch->activeMutes($supplierId),
            'daily_limit' => AiJobService::DAILY_JOB_LIMIT,
            'today_used' => $this->jobs->todayUsed($supplierId),
        ];
    }

    private function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }
}
