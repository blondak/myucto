<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Výchozí dimenze klienta a zakázky (Firma → Dimenze, migrace 1861).
 *
 *   GET|PUT /api/clients/{id}/dimensions        — výchozí dimenze klienta (odběratel i dodavatel)
 *   GET|PUT /api/projects/{id}/dimensions       — výchozí dimenze zakázky
 *   GET     /api/accounting/dimensions/prefill  — předvyplnění hlavičky dokladu v editoru
 *            ?client_id=&project_id=&invoice_id=|purchase_invoice_id=
 *
 * Práva zrcadlí RoutePermissionMap: čtení = `clients`/`projects` READ, uložení =
 * stejné právo jako úprava karty (`clients`/`projects` WRITE). Předvyplnění patří
 * k editoru dokladu, a tedy k dimenzím — `accounting` READ.
 */
final class DimensionDefaultsAction
{
    use AccountingActionSupport;

    public function __construct(
        private readonly DimensionService $dimensions,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function getClient(Request $request, Response $response, array $args): Response
    {
        return $this->read($request, $response, 'client', (int) ($args['id'] ?? 0));
    }

    public function saveClient(Request $request, Response $response, array $args): Response
    {
        return $this->save($request, $response, 'client', (int) ($args['id'] ?? 0));
    }

    public function getProject(Request $request, Response $response, array $args): Response
    {
        return $this->read($request, $response, 'project', (int) ($args['id'] ?? 0));
    }

    public function saveProject(Request $request, Response $response, array $args): Response
    {
        return $this->save($request, $response, 'project', (int) ($args['id'] ?? 0));
    }

    public function prefill(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'accounting', AccessLevel::READ, $err)) return $err;
        $q = $request->getQueryParams();
        $id = static fn (string $key): ?int => isset($q[$key]) && (int) $q[$key] > 0 ? (int) $q[$key] : null;
        [$linkedType, $linkedId] = match (true) {
            $id('invoice_id') !== null => ['invoice', $id('invoice_id')],
            $id('purchase_invoice_id') !== null => ['purchase_invoice', $id('purchase_invoice_id')],
            default => [null, null],
        };
        $result = $this->dimensions->prefill(
            $this->currentSupplierId($request),
            $id('client_id'),
            $id('project_id'),
            $linkedType,
            $linkedId,
        );
        return Json::ok($response, ['header' => (object) $result['header'], 'sources' => (object) $result['sources']]);
    }

    private function read(Request $request, Response $response, string $entity, int $id): Response
    {
        if (!$this->requirePermission($request, $response, self::permission($entity), AccessLevel::READ, $err)) return $err;
        try {
            return Json::ok($response, ['dimensions' => (object) $this->dimensions->entityDefaults(
                $this->currentSupplierId($request),
                $entity,
                $id,
            )]);
        } catch (DimensionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    private function save(Request $request, Response $response, string $entity, int $id): Response
    {
        if (!$this->requirePermission($request, $response, self::permission($entity), AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $saved = $this->dimensions->saveEntityDefaults($supplierId, $entity, $id, (array) ($body['dimensions'] ?? []));
        } catch (DimensionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $this->logger->log(
            'dimension.defaults_updated',
            $this->userId($request),
            $entity,
            $id,
            ['dimensions' => $saved],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
        return Json::ok($response, ['dimensions' => (object) $saved]);
    }

    private static function permission(string $entity): string
    {
        return $entity === 'project' ? 'projects' : 'clients';
    }
}
