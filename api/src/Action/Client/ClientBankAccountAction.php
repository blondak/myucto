<?php

declare(strict_types=1);

namespace MyInvoice\Action\Client;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\ClientBankAccountRepository;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\Ares\ClientBankAccountRegistrySynchronizer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ClientBankAccountAction
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly ClientBankAccountRepository $accounts,
        private readonly ClientBankAccountRegistrySynchronizer $registry,
    ) {}

    public function list(Request $request, Response $response, array $args): Response
    {
        $client = $this->ownedClient($request, (int) ($args['id'] ?? 0));
        if ($client === null) {
            return Json::error($response, 'not_found', 'Obchodní partner nebyl nalezen.', 404);
        }
        return Json::ok($response, $this->accounts->listForClient((int) $client['id'], (int) $client['supplier_id']));
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        $client = $this->ownedClient($request, (int) ($args['id'] ?? 0));
        if ($client === null) {
            return Json::error($response, 'not_found', 'Obchodní partner nebyl nalezen.', 404);
        }
        try {
            $account = $this->accounts->addManual(
                (int) $client['id'],
                (int) $client['supplier_id'],
                (array) ($request->getParsedBody() ?? []),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'invalid_bank_account', $e->getMessage(), 422);
        }
        return Json::ok($response, $account, 201);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $client = $this->ownedClient($request, (int) ($args['id'] ?? 0));
        if ($client === null) {
            return Json::error($response, 'not_found', 'Obchodní partner nebyl nalezen.', 404);
        }
        $deleted = $this->accounts->deactivate(
            (int) ($args['accountId'] ?? 0),
            (int) $client['id'],
            (int) $client['supplier_id'],
        );
        return $deleted
            ? Json::ok($response, ['deleted' => true])
            : Json::error($response, 'not_found', 'Bankovní účet nebyl nalezen.', 404);
    }

    public function syncRegistry(Request $request, Response $response, array $args): Response
    {
        $client = $this->ownedClient($request, (int) ($args['id'] ?? 0));
        if ($client === null) {
            return Json::error($response, 'not_found', 'Obchodní partner nebyl nalezen.', 404);
        }
        $result = $this->registry->sync(
            (int) $client['id'],
            (int) $client['supplier_id'],
            isset($client['dic']) ? (string) $client['dic'] : null,
        );
        $result['accounts'] = $this->accounts->listForClient((int) $client['id'], (int) $client['supplier_id']);
        return Json::ok($response, $result);
    }

    /** @return array<string,mixed>|null */
    private function ownedClient(Request $request, int $id): ?array
    {
        $client = $this->clients->find($id);
        return SupplierGuard::owns($request, $client) ? $client : null;
    }
}
