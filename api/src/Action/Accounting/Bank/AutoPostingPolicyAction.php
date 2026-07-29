<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Bank;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AutoPostingPolicyService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AutoPostingPolicyAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly AutoPostingPolicyService $policy,
        private readonly Connection $db,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        return Json::ok($response, $this->policy->listPolicy($supplierId));
    }

    public function put(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) $pdo->beginTransaction();
        try {
            if (array_key_exists('automation_level', $body)) {
                $this->policy->applyPreset($supplierId, (string) $body['automation_level'], $this->userId($request));
            }
            foreach ((array) ($body['rows'] ?? []) as $row) {
                if (!is_array($row)) continue;
                $this->policy->upsertRow(
                    $supplierId,
                    (string) ($row['operation_type'] ?? ''),
                    (string) ($row['level'] ?? ''),
                    $this->userId($request),
                );
            }
            if (array_key_exists('automation_daily_limit_czk', $body)
                || array_key_exists('automation_digest_enabled', $body)
                || array_key_exists('automation_digest_hour', $body)) {
                $current = $this->policy->listPolicy($supplierId);
                $daily = array_key_exists('automation_daily_limit_czk', $body)
                    ? ($body['automation_daily_limit_czk'] === null || $body['automation_daily_limit_czk'] === ''
                        ? null : (float) $body['automation_daily_limit_czk'])
                    : $current['automation_daily_limit_czk'];
                $digest = array_key_exists('automation_digest_enabled', $body)
                    ? (bool) filter_var($body['automation_digest_enabled'], FILTER_VALIDATE_BOOLEAN)
                    : (bool) $current['automation_digest_enabled'];
                $hour = array_key_exists('automation_digest_hour', $body)
                    ? (int) $body['automation_digest_hour']
                    : (int) $current['automation_digest_hour'];
                $this->policy->updateSettings($supplierId, $daily, $digest, $hour);
            }
            if ($owns) $pdo->commit();
        } catch (\MyInvoice\Service\Accounting\PostingException $e) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            return $this->mapPostingError($response, $e);
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return Json::ok($response, $this->policy->listPolicy($supplierId));
    }
}
