<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Integration\ConnectorDefinitions;
use MyInvoice\Service\Integration\IntegrationConnectionDefaults;
use MyInvoice\Service\Integration\IntegrationConnectionService;
use MyInvoice\Service\Integration\IntegrationDeliveryService;
use MyInvoice\Service\Integration\IntegrationDiagnosticsService;
use MyInvoice\Service\Integration\IntegrationLocalLookups;
use MyInvoice\Service\Integration\IntegrationReconcileService;
use MyInvoice\Service\Integration\IntegrationSampleProvisioner;
use MyInvoice\Service\Integration\IntegrationValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class IntegrationAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly IntegrationConnectionService $connections,
        private readonly IntegrationDiagnosticsService $diagnostics,
        private readonly IntegrationDeliveryService $delivery,
        private readonly IntegrationReconcileService $reconcile,
        private readonly CatalogJobService $jobs,
        private readonly ConnectorDefinitions $definitions,
        private readonly IntegrationLocalLookups $lookups,
        private readonly IntegrationConnectionDefaults $defaults,
        private readonly IntegrationSampleProvisioner $samples,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        // Ukázkové napojení vzniká jen pro uživatele, který smí integrace upravovat.
        // Chyba při jeho založení nesmí zablokovat zobrazení seznamu.
        if (RequestAuthorization::allows($request, 'eshop.integrations', AccessLevel::WRITE)) {
            try {
                $this->samples->ensure($supplierId, $this->userId($request));
            } catch (\Throwable $e) {
                error_log('Integration sample provisioning failed for supplier ' . $supplierId . ': ' . $e->getMessage());
            }
        }
        return Json::ok($response, $this->connections->list($supplierId));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $error;
        }
        return $this->connections->delete($this->currentSupplierId($request), (int) $args['id'])
            ? Json::ok($response, ['deleted' => true])
            : Json::error($response, 'not_found', 'Připojení nenalezeno.', 404);
    }

    /** Definice konektorů a místní číselníky firmy pro editor mapování. */
    public function connectors(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        return Json::ok($response, [
            'connectors' => $this->definitions->all(),
            'owners' => ConnectorDefinitions::OWNERS,
            'lookups' => $this->lookups->all($supplierId),
            'defaults' => $this->defaults->all($supplierId),
        ]);
    }

    /** Založí ukázkové napojení (Koncept) s výchozím nastavením. Jen na kliknutí uživatele. */
    public function sample(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $error;
        }
        try {
            $connection = $this->connections->createSample($this->currentSupplierId($request), $this->userId($request));
        } catch (\InvalidArgumentException $e) {
            return $this->validationError($response, $e);
        }
        return Json::ok($response, $connection, 201);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $error;
        }
        try {
            $connection = $this->connections->create($this->currentSupplierId($request),
                (array) ($request->getParsedBody() ?? []), $this->userId($request));
        } catch (\InvalidArgumentException $e) {
            return $this->validationError($response, $e);
        }
        return Json::ok($response, $connection, 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $error;
        }
        try {
            $connection = $this->connections->update($this->currentSupplierId($request), (int) $args['id'],
                (array) ($request->getParsedBody() ?? []));
        } catch (\InvalidArgumentException $e) {
            return $this->validationError($response, $e);
        }
        return $connection === null
            ? Json::error($response, 'not_found', 'Připojení nenalezeno.', 404)
            : Json::ok($response, $connection);
    }

    public function credentials(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $error;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $connection = $this->connections->setCredentials($this->currentSupplierId($request), (int) $args['id'],
                is_array($body['credentials'] ?? null) ? $body['credentials'] : [],
                is_array($body['clear'] ?? null) ? array_values($body['clear']) : []);
        } catch (\InvalidArgumentException $e) {
            return $this->validationError($response, $e);
        }
        return $connection === null
            ? Json::error($response, 'not_found', 'Připojení nenalezeno.', 404)
            : Json::ok($response, $connection);
    }

    public function rotateWebhookSecret(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $error;
        }
        $secret = $this->connections->rotateWebhookSecret($this->currentSupplierId($request), (int) $args['id']);
        return $secret === null
            ? Json::error($response, 'not_found', 'Připojení nenalezeno.', 404)
            : Json::ok($response, $secret);
    }

    public function diagnostics(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $connectionId = (int) $args['id'];
        $data = $this->diagnostics->overview($supplierId, $connectionId);
        if ($data === null) {
            return Json::error($response, 'not_found', 'Připojení nenalezeno.', 404);
        }
        $jobs = array_filter(
            $this->jobs->history($supplierId, PHP_INT_MAX, 20, [IntegrationReconcileService::KIND]),
            static fn (array $job): bool => (int) ($job['input']['connection_id'] ?? 0) === $connectionId,
        );
        $data['jobs'] = array_map(static function (array $job): array {
            unset($job['input']);
            return $job;
        }, array_values($jobs));
        return Json::ok($response, $data);
    }

    public function reconcile(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $id = $this->reconcile->enqueue($supplierId, (int) $args['id'], $this->userId($request));
        if ($id === 0) {
            return Json::error($response, 'not_found', 'Připojení nenalezeno.', 404);
        }
        return Json::ok($response, $this->jobs->find($supplierId, $id), 202);
    }

    public function retryOutbox(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $error;
        }
        $changed = $this->delivery->retryDeadLetter($this->currentSupplierId($request),
            (int) $args['id'], (int) $args['eventId']);
        return $changed
            ? Json::ok($response, ['retried' => true])
            : Json::error($response, 'event_state_conflict', 'Událost není ve frontě trvalých chyb.', 409);
    }

    private function validationError(Response $response, \InvalidArgumentException $e): Response
    {
        $extra = $e instanceof IntegrationValidationException && $e->field !== null ? ['field' => $e->field] : [];
        return Json::error($response, 'validation_failed', $e->getMessage(), 422, $extra);
    }

    private function guard(Request $request, Response $response, AccessLevel $level, ?Response &$error): bool
    {
        if (!$this->requirePermission($request, $response, 'eshop.integrations', $level, $error)) {
            return false;
        }
        return $this->guardStockEnabled($this->db, $this->currentSupplierId($request), $response, $error);
    }
}
