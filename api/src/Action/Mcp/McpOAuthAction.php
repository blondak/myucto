<?php

declare(strict_types=1);

namespace MyInvoice\Action\Mcp;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\OneTimeTokenException;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Service\Mcp\HostedMcp;
use MyInvoice\Service\Mcp\McpOAuth;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class McpOAuthAction
{
    public function __construct(
        private readonly HostedMcp $hosted,
        private readonly McpOAuth $oauth,
        private readonly Connection $db,
        private readonly UserSupplierRepository $memberships,
        private readonly SupplierAccessResolver $suppliers,
        private readonly PermissionResolver $roles,
        private readonly PermissionChecker $permissions,
        private readonly PasswordHasher $hasher,
        private readonly TotpService $totp,
        private readonly SecretEncryption $crypto,
        private readonly BruteForceGuard $bruteForce,
        private readonly IpMatcher $ipMatcher,
        private readonly PasskeyCredentialRepository $credentials,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly MfaStepUpService $stepUp,
    ) {}

    public function protectedResource(Request $request, Response $response): Response
    {
        if (!$this->hosted->enabled()) return $response->withStatus(404);
        return Json::ok($response, [
            'resource' => $this->hosted->endpoint(),
            'authorization_servers' => [$this->base()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => ['read', 'read_write', 'offline_access'],
            'resource_name' => 'MyÚčto MCP',
        ]);
    }

    public function serverMetadata(Request $request, Response $response): Response
    {
        if (!$this->hosted->enabled()) return $response->withStatus(404);
        return Json::ok($response, [
            'issuer' => $this->base(),
            'authorization_endpoint' => $this->base() . '/oauth/authorize',
            'token_endpoint' => $this->base() . '/oauth/token',
            'registration_endpoint' => $this->base() . '/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['read', 'read_write', 'offline_access'],
        ]);
    }

    public function register(Request $request, Response $response): Response
    {
        if (!$this->hosted->enabled()) return $response->withStatus(404);
        if ($request->getBody()->getSize() > 16384) return $this->oauthError($response, 'invalid_client_metadata', 413);
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) return $this->oauthError($response, 'invalid_client_metadata', 400);
        if (isset($body['client_name']) && !is_string($body['client_name'])) {
            return $this->oauthError($response, 'invalid_client_metadata', 400);
        }
        $name = trim($body['client_name'] ?? 'MCP klient');
        $redirects = $body['redirect_uris'] ?? null;
        if ($name === '' || mb_strlen($name) > 100 || !is_array($redirects)
            || array_is_list($redirects) === false || count($redirects) < 1 || count($redirects) > 10
            || ($body['token_endpoint_auth_method'] ?? 'none') !== 'none'
        ) {
            return $this->oauthError($response, 'invalid_client_metadata', 400);
        }
        foreach ($redirects as $uri) {
            if (!is_string($uri) || !$this->validRedirect($uri)) {
                return $this->oauthError($response, 'invalid_redirect_uri', 400);
            }
        }
        $redirects = array_values(array_unique($redirects));
        $id = $this->oauth->register($name, $redirects);
        return Json::ok($response, [
            'client_id' => $id,
            'client_name' => $name,
            'redirect_uris' => $redirects,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'client_id_issued_at' => time(),
        ], 201);
    }

    public function authorize(Request $request, Response $response): Response
    {
        if (!$this->hosted->enabled()) return $response->withStatus(404);
        if ($request->getMethod() === 'POST' && $request->getBody()->getSize() > 8192) {
            return $this->html($response, '<h1>Neplatný požadavek na připojení MCP.</h1>', 413);
        }
        $params = $request->getMethod() === 'POST'
            ? (array) $request->getParsedBody()
            : $request->getQueryParams();
        foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge',
            'code_challenge_method', 'state', 'resource', 'scope', 'supplier_id', 'decision',
            'password', 'totp_code', 'step_up_token'] as $key) {
            if (isset($params[$key]) && !is_string($params[$key]) && !is_int($params[$key])) {
                return $this->html($response, '<h1>Neplatný požadavek na připojení MCP.</h1>', 400);
            }
        }
        $clientId = (string) ($params['client_id'] ?? '');
        $client = $this->oauth->client($clientId);
        $redirect = (string) ($params['redirect_uri'] ?? '');
        $challenge = (string) ($params['code_challenge'] ?? '');
        $state = (string) ($params['state'] ?? '');
        $resource = (string) ($params['resource'] ?? $this->hosted->endpoint());
        $scope = $this->scope((string) ($params['scope'] ?? 'read'));
        if ($client === null
            || !in_array($redirect, $client['redirect_uris'], true)
            || ($params['response_type'] ?? '') !== 'code'
            || ($params['code_challenge_method'] ?? '') !== 'S256'
            || preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $challenge) !== 1
            || strlen($state) > 512 || $resource !== $this->hosted->endpoint()
            || $scope === null
        ) {
            return $this->html($response, '<h1>Neplatný požadavek na připojení MCP.</h1>', 400);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if ((int) ($user['id'] ?? 0) < 1) {
            $target = '/oauth/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $location = '/login?return_to=' . rawurlencode('/oauth/continue?target=' . rawurlencode($target));
            return $response->withStatus(302)->withHeader('Location', $location)->withHeader('Cache-Control', 'no-store');
        }
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return $this->html($response, '<h1>Připojení vyžaduje přihlášení v prohlížeči.</h1>', 401);
        }

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($request->getMethod() === 'POST') {
            $requestedSupplier = (int) ($params['supplier_id'] ?? 0);
            $access = $this->suppliers->resolve($request->withQueryParams(['supplier_id' => $requestedSupplier]));
            if ($requestedSupplier < 1 || $access->denied || $access->supplierId !== $requestedSupplier) {
                return $this->html($response, '<h1>K této firmě nemáte přístup.</h1>', 403);
            }
            $supplierId = $requestedSupplier;
        }
        $choices = [];
        if ($request->getMethod() === 'GET') {
            $available = ($user['is_superadmin'] ?? false) === true
                ? $this->db->pdo()->query('SELECT id AS supplier_id, COALESCE(NULLIF(display_name, \'\'), company_name) AS name FROM supplier ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC)
                : $this->memberships->listForUser((int) $user['id']);
            foreach ($available as $choice) {
                $candidateId = (int) $choice['supplier_id'];
                $candidateRequest = $request->withQueryParams(['supplier_id' => $candidateId]);
                $access = $this->suppliers->resolve($candidateRequest);
                if (!$access->denied && $access->supplierId === $candidateId
                    && $this->permissions->allows(
                        $this->roles->resolve($candidateRequest), 'profile.tokens', AccessLevel::WRITE,
                    )
                ) {
                    $choices[] = $choice;
                }
            }
            if ($choices === []) {
                return $this->html($response, '<h1>Nemáte oprávnění k vydání API tokenu pro žádnou firmu.</h1>', 403);
            }
            if (!in_array($supplierId, array_map(static fn (array $choice): int => (int) $choice['supplier_id'], $choices), true)) {
                $supplierId = (int) $choices[0]['supplier_id'];
            }
        } else {
            if ($supplierId < 1) {
                return $this->html($response, '<h1>Uživatel nemá přístup k žádné firmě.</h1>', 403);
            }
            $selectedRequest = $request->withQueryParams(['supplier_id' => $supplierId]);
            if (!$this->permissions->allows($this->roles->resolve($selectedRequest), 'profile.tokens', AccessLevel::WRITE)) {
                return $this->html($response, '<h1>Nemáte oprávnění k vydání API tokenu.</h1>', 403);
            }
        }
        $identity = $this->db->pdo()->prepare(
            'SELECT email, password_hash, totp_secret, totp_enabled FROM users WHERE id = ? AND is_active = 1'
        );
        $identity->execute([(int) $user['id']]);
        $credentials = $identity->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($credentials)) return $this->html($response, '<h1>Účet není aktivní.</h1>', 403);
        $totpRequired = (int) $credentials['totp_enabled'] === 1 && $credentials['totp_secret'] !== null;
        $passkeyAvailable = $this->mfaPolicy->isMethodAllowed('passkey')
            && $this->credentials->countActiveForUser((int) $user['id']) > 0;

        if ($request->getMethod() === 'GET') {
            $session = (array) $request->getAttribute(AuthMiddleware::ATTR_SESSION, []);
            $csrf = (string) ($session['csrf_token'] ?? '');
            $host = parse_url($redirect, PHP_URL_HOST) ?: $redirect;
            $fields = '';
            foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge',
                'code_challenge_method', 'state', 'resource', 'scope'] as $key) {
                $value = (string) ($params[$key] ?? ($key === 'resource' ? $resource : ($key === 'scope' ? $scope : '')));
                $fields .= '<input type="hidden" name="' . $key . '" value="' . self::escape($value) . '">';
            }
            $fields .= '<input type="hidden" name="csrf_token" value="' . self::escape($csrf) . '">';
            $select = '<label>Firma <select name="supplier_id" required>';
            foreach ($choices as $choice) {
                $id = (int) $choice['supplier_id'];
                $selected = $id === $supplierId ? ' selected' : '';
                $select .= '<option value="' . $id . '"' . $selected . '>'
                    . self::escape((string) $choice['name']) . '</option>';
            }
            $select .= '</select></label>';
            $verification = '';
            if ($passkeyAvailable) {
                $verification .= '<input type="hidden" name="step_up_token" id="mcp-step-up-token">'
                    . '<button type="button" id="mcp-passkey-button">Ověřit passkey</button>'
                    . '<span id="mcp-passkey-status" role="status"></span>';
            }
            if ($totpRequired) {
                $verification .= '<label>Aktuální kód ověřovací aplikace <input name="totp_code" autocomplete="one-time-code" inputmode="numeric"></label>';
            } elseif (!$passkeyAvailable) {
                $verification = '<label>Aktuální heslo <input type="password" name="password" autocomplete="current-password" required></label>';
            }
            $permission = $scope === 'read_write' ? 'čtení a zápis' : 'pouze čtení';
            $content = '<h1>Připojit MyÚčto k AI asistentovi?</h1>'
                . '<p>Aplikace <strong>' . self::escape((string) $client['client_name']) . '</strong> ('
                . self::escape((string) $host) . ') žádá o přístup: <strong>' . $permission . '</strong>.</p>'
                . '<p>Rozsah platí pro aktuální firmu. Přístup můžete zrušit v API tokenech.</p>'
                . '<form method="post" action="/oauth/authorize">' . $fields . $select . $verification
                . '<button type="submit" name="decision" value="approve">Povolit přístup</button> '
                . '<button type="submit" name="decision" value="deny">Zamítnout</button></form>'
                . ($passkeyAvailable ? '<script src="/assets/mcp-consent-v1.js" defer></script>' : '');
            return $this->html($response, $content);
        }

        if (($params['decision'] ?? '') !== 'approve') {
            return $response->withStatus(302)->withHeader('Location', self::redirectWith($redirect, [
                'error' => 'access_denied', 'state' => $state,
            ]))->withHeader('Cache-Control', 'no-store');
        }
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $proof = (string) ($params['step_up_token'] ?? '');
        if ($proof !== '') {
            try {
                $this->stepUp->consume(
                    $proof, (int) $user['id'],
                    (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, ''),
                    MfaStepUpService::OPERATION_API_TOKEN_CREATE,
                );
            } catch (OneTimeTokenException|StepUpOperationException) {
                return $this->html($response, '<h1>Ověření passkey je neplatné nebo vypršelo.</h1>', 403);
            }
        } elseif ($totpRequired) {
            if ($this->bruteForce->isTotpLocked((int) $user['id'])) {
                return $this->html($response, '<h1>Příliš mnoho pokusů. Zkuste to později.</h1>', 429);
            }
            try {
                $secret = $this->crypto->decrypt((string) $credentials['totp_secret']);
            } catch (\RuntimeException) {
                return $this->html($response, '<h1>Ověření nyní není dostupné.</h1>', 500);
            }
            if (!$this->totp->verifyAndConsume($this->db, $secret, (string) ($params['totp_code'] ?? ''))) {
                $this->bruteForce->recordTotpFailure((int) $user['id']);
                return $this->html($response, '<h1>Neplatný ověřovací kód.</h1>', 401);
            }
            $this->bruteForce->recordTotpSuccess((int) $user['id']);
        } elseif ($passkeyAvailable) {
            return $this->html($response, '<h1>Pro připojení je nutné ověřit passkey.</h1>', 401);
        } else {
            $email = (string) $credentials['email'];
            if (in_array($this->bruteForce->check($email, $ip), [
                BruteForceGuard::STATE_LOCKED_15M, BruteForceGuard::STATE_LOCKED_24H,
            ], true)) {
                return $this->html($response, '<h1>Příliš mnoho pokusů. Zkuste to později.</h1>', 429);
            }
            if (!$this->hasher->verify((string) ($params['password'] ?? ''), (string) $credentials['password_hash'])) {
                $this->hasher->dummyVerify();
                $this->bruteForce->recordFailure($email, $ip);
                return $this->html($response, '<h1>Neplatné heslo.</h1>', 401);
            }
        }
        $code = $this->oauth->createCode(
            $clientId, (int) $user['id'], $supplierId, $redirect, $challenge, $scope, $resource,
        );
        return $response->withStatus(302)->withHeader('Location', self::redirectWith($redirect, [
            'code' => $code, 'state' => $state,
        ]))->withHeader('Cache-Control', 'no-store');
    }

    public function token(Request $request, Response $response): Response
    {
        if (!$this->hosted->enabled()) return $response->withStatus(404);
        if ($request->getBody()->getSize() > 8192) return $this->oauthError($response, 'invalid_request', 413);
        $params = (array) $request->getParsedBody();
        foreach (['grant_type', 'client_id', 'code_verifier', 'code', 'redirect_uri', 'resource', 'refresh_token'] as $key) {
            if (isset($params[$key]) && !is_string($params[$key])) {
                return $this->oauthError($response, 'invalid_request', 400);
            }
        }
        $grant = (string) ($params['grant_type'] ?? '');
        $clientId = (string) ($params['client_id'] ?? '');
        if ($this->oauth->client($clientId) === null) {
            return $this->oauthError($response, 'invalid_client', 401);
        }
        if ($grant === 'authorization_code') {
            $verifier = (string) ($params['code_verifier'] ?? '');
            if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) !== 1) {
                return $this->oauthError($response, 'invalid_grant', 400);
            }
            $result = $this->oauth->exchange(
                (string) ($params['code'] ?? ''), $clientId,
                (string) ($params['redirect_uri'] ?? ''), $verifier,
                (string) ($params['resource'] ?? $this->hosted->endpoint()),
            );
        } elseif ($grant === 'refresh_token') {
            $result = $this->oauth->refresh((string) ($params['refresh_token'] ?? ''), $clientId);
        } else {
            return $this->oauthError($response, 'unsupported_grant_type', 400);
        }
        return $result !== null ? Json::ok($response, $result) : $this->oauthError($response, 'invalid_grant', 400);
    }

    private function base(): string
    {
        return substr($this->hosted->endpoint(), 0, -4);
    }

    private function validRedirect(string $uri): bool
    {
        if (strlen($uri) > 2048 || str_contains($uri, '#')) return false;
        $parts = parse_url($uri);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) return false;
        if ($parts['scheme'] === 'https') return true;
        return $parts['scheme'] === 'http'
            && in_array(strtolower((string) $parts['host']), ['localhost', '127.0.0.1', '::1'], true);
    }

    private function scope(string $scope): ?string
    {
        $items = array_values(array_filter(explode(' ', trim($scope))));
        foreach ($items as $item) {
            if (!in_array($item, ['read', 'read_write', 'offline_access'], true)) return null;
        }
        return in_array('read_write', $items, true) ? 'read_write' : 'read';
    }

    private function html(Response $response, string $content, int $status = 200): Response
    {
        $body = '<!doctype html><html lang="cs"><meta charset="utf-8"><meta name="viewport" '
            . 'content="width=device-width, initial-scale=1"><title>MyÚčto MCP</title>'
            . '<style>body{font:16px system-ui;max-width:44rem;margin:4rem auto;padding:0 1rem;line-height:1.5}'
            . 'button{padding:.7rem 1rem;margin:.5rem .5rem .5rem 0;cursor:pointer}</style>'
            . '<main>' . $content . '</main></html>';
        $response->getBody()->write($body);
        return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function oauthError(Response $response, string $error, int $status): Response
    {
        return Json::ok($response, ['error' => $error], $status);
    }

    private static function redirectWith(string $url, array $query): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
