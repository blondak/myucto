<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Účtová osnova (chart of accounts) — REST API (Epic F1).
 *
 *   GET   /api/accounting/accounts        — seznam (?tree=1 = strom, ?include_inactive=1)
 *   POST  /api/accounting/accounts        — nová analytika (dítě syntetiky) — účetní|admin
 *   PATCH /api/accounting/accounts/{id}   — přejmenování / deaktivace — účetní|admin
 *
 * Účty se NEmažou (auditní stopa) — jen deaktivují (is_active=0).
 */
final class ChartOfAccountsAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly ChartOfAccountsRepository $accounts,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $includeInactive = !empty($q['include_inactive']);

        if (!empty($q['tree'])) {
            return Json::ok($response, $this->accounts->tree($supplierId, $includeInactive));
        }
        return Json::ok($response, $this->accounts->listForTenant($supplierId, $includeInactive));
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);

        $parentId = (int) ($body['parent_id'] ?? 0);
        $code = trim((string) ($body['account_code'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));

        if ($parentId <= 0) {
            return Json::error($response, 'validation_failed', 'parent_id je povinné (analytika je dítě syntetického účtu).', 422);
        }
        if ($code === '' || $name === '') {
            return Json::error($response, 'validation_failed', 'account_code a name jsou povinné.', 422);
        }
        if (!preg_match('/^[0-9A-Za-z.]{3,10}$/', $code)) {
            return Json::error($response, 'validation_failed', 'account_code musí mít 3–10 znaků (číslice/písmena/tečka).', 422);
        }

        $parent = $this->accounts->findById($supplierId, $parentId);
        if ($parent === null) {
            return Json::error($response, 'not_found', 'Rodičovský účet nenalezen.', 404);
        }
        if ($this->accounts->findByCode($supplierId, $code) !== null) {
            return Json::error($response, 'duplicate_account', 'Účet s tímto kódem už v osnově existuje.', 409);
        }

        // Analytika dědí typ i normální stranu po syntetickém rodiči.
        $id = $this->accounts->insert($supplierId, [
            'account_code' => $code,
            'name'         => $name,
            'account_type' => (string) $parent['account_type'],
            'normal_side'  => $parent['normal_side'] !== null ? (string) $parent['normal_side'] : null,
            'is_synthetic' => false,
            'parent_id'    => $parentId,
            'is_active'    => true,
        ]);

        $this->log($request, 'accounting.account_created', $id, ['account_code' => $code, 'parent_id' => $parentId]);
        return Json::ok($response, $this->accounts->findById($supplierId, $id), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        $existing = $this->accounts->findById($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Účet nenalezen.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $data = [];
        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return Json::error($response, 'validation_failed', 'name nesmí být prázdné.', 422);
            }
            $data['name'] = $name;
        }
        if (array_key_exists('is_active', $body)) {
            $data['is_active'] = (bool) $body['is_active'];
        }
        if ($data === []) {
            return Json::ok($response, $existing);
        }

        $this->accounts->update($supplierId, $id, $data);
        $this->log($request, 'accounting.account_updated', $id, ['fields' => array_keys($data)]);
        return Json::ok($response, $this->accounts->findById($supplierId, $id));
    }

    private function log(Request $request, string $action, int $entityId, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log(
            $action,
            $this->userId($request),
            'chart_of_accounts',
            $entityId,
            $payload,
            $ip,
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
