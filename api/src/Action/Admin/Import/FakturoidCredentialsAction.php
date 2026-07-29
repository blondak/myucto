<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\FakturoidClient;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET    /api/admin/imports/fakturoid/credentials  — status
 * PUT    /api/admin/imports/fakturoid/credentials  — set + test
 * DELETE /api/admin/imports/fakturoid/credentials  — remove
 *
 * Body PUT (libovolná kombinace, ale aspoň jedna auth metoda musí být plná):
 *   - slug (povinné)
 *   - email + api_key            → legacy BasicAuth (původní personal API token)
 *   - client_id + client_secret  → OAuth2 Client Credentials (issue #31)
 *
 * Pokud jsou vyplněné oba bloky, OAuth2 má prioritu při API requestech.
 */
final class FakturoidCredentialsAction
{
    public function __construct(
        private readonly FakturoidClient $fakturoid,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'utilities.import', AccessLevel::WRITE)) return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        $supplierId = SupplierGuard::currentId($request);
        $creds = $this->fakturoid->getCredentials($supplierId);
        $hasOAuth = $creds !== null
            && !empty($creds['client_id'])
            && !empty($creds['client_secret']);
        $hasBasic = $creds !== null
            && !empty($creds['email'])
            && !empty($creds['api_key']);
        return Json::ok($response, [
            'configured' => $creds !== null,
            'slug'       => $creds['slug']      ?? null,
            'email'      => $creds['email']     ?? null,
            'client_id'  => $creds['client_id'] ?? null,
            'auth_mode'  => $hasOAuth ? 'oauth2' : ($hasBasic ? 'basic' : null),
            'has_oauth'  => $hasOAuth,
            'has_basic'  => $hasBasic,
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'utilities.import', AccessLevel::WRITE)) return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        $supplierId = SupplierGuard::currentId($request);

        $body = (array) ($request->getParsedBody() ?? []);
        $slug         = trim((string) ($body['slug']          ?? ''));
        $email        = trim((string) ($body['email']         ?? ''));
        $apiKey       = (string) ($body['api_key']            ?? '');
        $clientId     = trim((string) ($body['client_id']     ?? ''));
        $clientSecret = (string) ($body['client_secret']      ?? '');

        if ($slug === '') {
            return Json::error($response, 'validation_failed', 'Pole slug je povinné.', 400);
        }
        if (strlen($slug) > 64) {
            return Json::error($response, 'validation_failed', 'Slug přesahuje 64 znaků.', 400);
        }

        $wantsBasic = $email !== '' || $apiKey !== '';
        $wantsOAuth = $clientId !== '' || $clientSecret !== '';

        if (!$wantsBasic && !$wantsOAuth) {
            return Json::error($response, 'validation_failed',
                'Vyplň buď email + API token (legacy), nebo Client ID + Client Secret (OAuth2).', 400);
        }

        // Validace BasicAuth bloku (pokud aspoň jedno pole je vyplněné, vyžaduj obě)
        if ($wantsBasic) {
            if ($email === '' || $apiKey === '') {
                return Json::error($response, 'validation_failed',
                    'Pro legacy BasicAuth je nutné vyplnit email i API token.', 400);
            }
            if (strlen($email) > 255 || strlen($apiKey) > 512) {
                return Json::error($response, 'validation_failed', 'Email/API token přesahuje délkový limit.', 400);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return Json::error($response, 'validation_failed', 'Neplatný formát emailu.', 400);
            }
        }

        // Validace OAuth2 bloku
        if ($wantsOAuth) {
            if ($clientId === '' || $clientSecret === '') {
                return Json::error($response, 'validation_failed',
                    'Pro OAuth2 je nutné vyplnit Client ID i Client Secret.', 400);
            }
            if (strlen($clientId) > 190 || strlen($clientSecret) > 512) {
                return Json::error($response, 'validation_failed',
                    'Client ID / Client Secret přesahuje délkový limit.', 400);
            }
        }

        if ($wantsBasic) {
            $this->fakturoid->setCredentials($supplierId, $slug, $email, $apiKey);
        }
        if ($wantsOAuth) {
            $this->fakturoid->setOAuthCredentials($supplierId, $slug, $clientId, $clientSecret);
        }

        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('import.fakturoid_credentials_set', $userId, 'supplier', $supplierId, [
            'slug'      => $slug,
            'email'     => $email !== '' ? $email : null,
            'client_id' => $clientId !== '' ? $clientId : null,
            'auth_mode' => $wantsOAuth ? 'oauth2' : 'basic',
        ], $ip, $request->getHeaderLine('User-Agent'));

        $test = $this->fakturoid->testConnection($supplierId);
        return Json::ok($response, [
            'saved'        => true,
            'auth_mode'    => $wantsOAuth ? 'oauth2' : 'basic',
            'test_ok'      => $test['ok'],
            'test_error'   => $test['ok'] ? null : ($test['error'] ?? null),
            'account_name' => $test['account_name'] ?? null,
        ]);
    }

    public function delete(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'utilities.import', AccessLevel::WRITE)) return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        $supplierId = SupplierGuard::currentId($request);
        $this->fakturoid->clearCredentials($supplierId);
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('import.fakturoid_credentials_removed', $userId, 'supplier', $supplierId, null,
            $ip, $request->getHeaderLine('User-Agent'));
        return Json::ok($response, ['ok' => true]);
    }
}
