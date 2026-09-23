<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemScheduleService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class OtherItemScheduleAction
{
    use AccountingActionSupport;

    public function __construct(private readonly OtherItemScheduleService $service) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::READ, $err)) return $err;
        return $this->run($response, fn () => ['items' => $this->service->list($this->currentSupplierId($request))]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::READ, $err)) return $err;
        return $this->run($response, fn () => $this->service->get($this->currentSupplierId($request), (int) $args['id']));
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        return $this->run($response, fn () => $this->service->create($this->currentSupplierId($request),
            (int) $args['item_id'], (array) ($request->getParsedBody() ?? []), $this->userId($request)), 201);
    }

    public function generate(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        return $this->run($response, fn () => $this->service->generate($this->currentSupplierId($request),
            (int) $args['id'], (string) ($body['through'] ?? ''), $this->userId($request)));
    }

    public function status(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        return $this->run($response, fn () => $this->service->setStatus($this->currentSupplierId($request),
            (int) $args['id'], (string) ($body['status'] ?? '')));
    }

    public function installments(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::READ, $err)) return $err;
        return $this->run($response, fn () => ['items' => $this->service->installments($this->currentSupplierId($request),
            (int) $args['item_id'])]);
    }

    public function setInstallments(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $rows = $body['items'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            return Json::error($response, 'other_items.error.invalid_installments', 'Zadejte seznam splátek.', 422);
        }
        return $this->run($response, fn () => ['items' => $this->service->setInstallments(
            $this->currentSupplierId($request), (int) $args['item_id'], $rows)]);
    }

    private function run(Response $response, callable $callback, int $status = 200): Response
    {
        try {
            return Json::ok($response, $callback(), $status);
        } catch (OtherItemException $e) {
            return Json::error($response, 'other_items.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }
}
