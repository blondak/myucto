<?php

declare(strict_types=1);

namespace MyInvoice\Action\Client;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientEmailContactRepository;
use MyInvoice\Repository\ClientBankAccountRepository;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\Ares\ClientBankAccountRegistrySynchronizer;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateClientAction
{
    public function __construct(
        private readonly ClientRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly ClientEmailContactRepository $emailContacts,
        private readonly ClientBankAccountRepository $bankAccounts,
        private readonly ClientBankAccountRegistrySynchronizer $bankAccountRegistry,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!SupplierGuard::owns($request, $this->repo->find($id))) {
            return Json::error($response, 'not_found', 'Klient nenalezen.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $errors = Validation::client($body);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        try {
            $backfilled = $this->repo->update($id, $body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        // E-mailové kontakty dle účelu (#86) — replace-all, jen pokud klíč v payloadu je
        // (partial update bez klíče kontakty nemění). Změny do activity logu.
        $contactsChanged = false;
        if (isset($body['email_contacts']) && is_array($body['email_contacts'])) {
            try {
                $this->emailContacts->replaceForClient($id, $supplierId, $body['email_contacts']);
                $contactsChanged = true;
            } catch (\DomainException $e) {
                return Json::error($response, 'invalid_email_contacts', $e->getMessage(), 422);
            }
        }
        if (!empty($body['is_vendor']) && !empty($body['dic'])) {
            try {
                $this->bankAccountRegistry->sync($id, $supplierId, (string) $body['dic']);
            } catch (\Throwable) {
            }
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('client.updated', $user['id'] ?? null, 'client', $id,
            $contactsChanged ? ['email_contacts' => $body['email_contacts']] : null,
            $ip, $request->getHeaderLine('User-Agent'));

        // *_category_backfilled = počet faktur, do kterých byla doplněna nově nastavená
        // výchozí kategorie nákladu / tržby (frontend ukáže toast).
        $client = $this->repo->find($id) ?? [];
        $client['email_contacts'] = $this->emailContacts->listForClient($id, $supplierId);
        $client['bank_accounts'] = $this->bankAccounts->listForClient($id, $supplierId);
        $client['expense_category_backfilled'] = $backfilled['expense'];
        $client['revenue_category_backfilled'] = $backfilled['revenue'];

        // Non-blocking varování (IČO mod 11 / DIČ formát — audit 2026-07).
        $warnings = Validation::clientWarnings($body);
        if (!empty($warnings)) {
            $client['_warnings'] = $warnings;
        }

        return Json::ok($response, $client);
    }
}
