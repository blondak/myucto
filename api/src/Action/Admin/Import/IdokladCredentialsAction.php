<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\IdokladClient;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET  /api/admin/imports/idoklad/credentials  — vrátí stav (configured/not)
 * PUT  /api/admin/imports/idoklad/credentials  — set credentials + test
 *
 * Body PUT: { client_id: string, client_secret: string, test_only?: bool }
 *
 * Secret nikdy nevracen ven (write-only). Při PUT s test_only=true se rovnou
 * volá testConnection() s novými credentials BEZ uložení (validace před save).
 */
final class IdokladCredentialsAction
{
    public function __construct(
        private readonly IdokladClient $idoklad,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'utilities.import', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $creds = $this->idoklad->getCredentials($supplierId);
        return Json::ok($response, [
            'configured' => $creds !== null,
            'client_id'  => $creds['client_id'] ?? null,  // public OK
            // client_secret nevracen
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'utilities.import', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);

        $body = (array) ($request->getParsedBody() ?? []);
        $clientId = trim((string) ($body['client_id'] ?? ''));
        $clientSecret = (string) ($body['client_secret'] ?? '');

        if ($clientId === '' || $clientSecret === '') {
            return Json::error($response, 'validation_failed', 'client_id i client_secret jsou povinné.', 400);
        }
        // ID typicky 40-50 chars, secret 64-128. Sanity bounds (anti-DoS).
        if (strlen($clientId) > 256 || strlen($clientSecret) > 512) {
            return Json::error($response, 'validation_failed', 'Credentials přesahují délkový limit.', 400);
        }

        $this->idoklad->setCredentials($supplierId, $clientId, $clientSecret);
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('import.idoklad_credentials_set', $userId, 'supplier', $supplierId, [
            'client_id_prefix' => substr($clientId, 0, 8) . '…',
        ], $ip, $request->getHeaderLine('User-Agent'));

        // Test connection po uložení
        $test = $this->idoklad->testConnection($supplierId);
        return Json::ok($response, [
            'saved'      => true,
            'test_ok'    => $test['ok'],
            'test_error' => $test['ok'] ? null : ($test['error'] ?? null),
        ]);
    }

    /**
     * DELETE /api/admin/imports/idoklad/credentials — odebrat credentials.
     * Nevolá zrušení běžících importů — ty doběhnou s cached tokenem (nebo selžou).
     */
    public function delete(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'utilities.import', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $this->idoklad->setCredentials($supplierId, '', '');
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('import.idoklad_credentials_removed', $userId, 'supplier', $supplierId, null,
            $ip, $request->getHeaderLine('User-Agent'));
        return Json::ok($response, ['ok' => true]);
    }
}
