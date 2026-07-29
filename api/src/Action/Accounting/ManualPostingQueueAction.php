<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\ManualPostingQueueService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Featura H (REAL_data_followup_UX.md) — jednotná fronta „čeká na ruční zaúčtování".
 * Čistě čtecí endpoint (žádná mutace) — read-only agregace napříč zdroji.
 */
final class ManualPostingQueueAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly ManualPostingQueueService $service,
        private readonly Connection $db,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $q = $request->getQueryParams();
        $result = $this->service->queue($supplierId, [
            'type' => isset($q['type']) ? (string) $q['type'] : null,
            'reason' => isset($q['reason']) ? (string) $q['reason'] : null,
            'page' => (int) ($q['page'] ?? 1),
            'per_page' => (int) ($q['per_page'] ?? 50),
        ]);

        return Json::ok($response, $result);
    }
}
