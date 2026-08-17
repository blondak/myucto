<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\OneTimeTokenException;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Tenant\DomainVerificationService;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SupplierDomainAction
{
    public function __construct(
        private readonly SupplierDomainRepository $domains,
        private readonly HostnameNormalizer $hostnames,
        private readonly DomainVerificationService $verification,
        private readonly MfaStepUpService $stepUp,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) return $error;
        return Json::ok($response, array_map($this->present(...), $this->domains->listForSupplier($this->supplierId($request))));
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) return $error;
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $hostname = $this->hostnames->normalizeDomain((string) ($body['hostname'] ?? ''));
            $domain = $this->domains->create(
                $this->supplierId($request),
                $hostname,
                (string) ($body['purpose'] ?? 'all'),
                $this->userId($request),
            );
            if (($body['is_primary'] ?? false) === true) {
                $domain = $this->domains->update(
                    $this->supplierId($request),
                    (int) $domain['id'],
                    (string) $domain['purpose'],
                    true,
                    $this->userId($request),
                );
            }
            $this->log($request, 'supplier_domain.created', (int) $domain['id'], [
                'hostname' => $hostname,
                'purpose' => $domain['purpose'],
            ]);
            return Json::ok($response, $this->present($domain), 201);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return Json::error($response, 'hostname_taken', 'Tento hostname už je přiřazený.', 409);
            }
            throw $e;
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) return $error;
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $domain = $this->domains->update(
                $this->supplierId($request),
                (int) ($args['id'] ?? 0),
                (string) ($body['purpose'] ?? 'all'),
                ($body['is_primary'] ?? false) === true,
                $this->userId($request),
            );
            $this->log($request, 'supplier_domain.updated', (int) $domain['id'], [
                'purpose' => $domain['purpose'],
                'is_primary' => $domain['is_primary'],
            ]);
            return Json::ok($response, $this->present($domain));
        } catch (\OutOfBoundsException) {
            return Json::error($response, 'not_found', 'Doména nebyla nalezena.', 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'domain_active', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
    }

    public function rotateChallenge(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) return $error;
        try {
            $domain = $this->domains->rotateChallenge(
                $this->supplierId($request),
                (int) ($args['id'] ?? 0),
                $this->userId($request),
            );
            $this->log($request, 'supplier_domain.challenge_rotated', (int) $domain['id'], []);
            return Json::ok($response, $this->present($domain));
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
    }

    public function verify(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) return $error;
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $domain = $this->domains->findOwned($sid, $id);
        if ($domain === null) return Json::error($response, 'not_found', 'Doména nebyla nalezena.', 404);
        try {
            $result = $this->verification->verify($domain);
            $this->domains->recordVerification(
                $sid,
                $id,
                $result['verified'],
                $result['error'],
                $this->userId($request),
            );
            $fresh = $this->domains->findOwned($sid, $id) ?? $domain;
            $this->log($request, 'supplier_domain.verification_checked', $id, [
                'verified' => $result['verified'],
                'dns' => $result['dns'],
                'https' => $result['https'],
            ]);
            return Json::ok($response, ['domain' => $this->present($fresh), 'checks' => $result]);
        } catch (\DomainException $e) {
            return Json::error($response, 'domain_active', $e->getMessage(), 409);
        }
    }

    public function activate(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) return $error;
        $body = (array) ($request->getParsedBody() ?? []);
        $id = (int) ($args['id'] ?? 0);
        try {
            $domain = $this->domains->findOwned($this->supplierId($request), $id);
            if ($domain === null) {
                return Json::error($response, 'not_found', 'Doména nebyla nalezena.', 404);
            }
            if ($domain['status'] !== 'verified' || $domain['verified_at'] === null) {
                return Json::error(
                    $response,
                    'domain_not_verified',
                    'Doména musí mít čerstvě ověřené DNS a HTTPS.',
                    409,
                );
            }
            $this->stepUp->consume(
                trim((string) ($body['step_up_token'] ?? '')),
                $this->userId($request),
                (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, ''),
                MfaStepUpService::domainActivationOperation($id),
            );
            $domain = $this->domains->activate(
                $this->supplierId($request),
                $id,
                ($body['is_primary'] ?? true) === true,
                $this->userId($request),
            );
            $this->log($request, 'supplier_domain.activated', $id, [
                'hostname' => $domain['hostname'],
                'purpose' => $domain['purpose'],
            ]);
            return Json::ok($response, $this->present($domain));
        } catch (OneTimeTokenException|StepUpOperationException $e) {
            return Json::error($response, 'step_up_required', $e->getMessage(), 403, [
                'operation' => MfaStepUpService::domainActivationOperation($id),
            ]);
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
    }

    public function disable(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) return $error;
        $id = (int) ($args['id'] ?? 0);
        try {
            $this->domains->disable($this->supplierId($request), $id, $this->userId($request));
            $this->log($request, 'supplier_domain.disabled', $id, []);
            return Json::ok($response, ['disabled' => true]);
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) return $error;
        $id = (int) ($args['id'] ?? 0);
        try {
            $this->domains->delete($this->supplierId($request), $id);
            $this->log($request, 'supplier_domain.deleted', $id, []);
            return Json::ok($response, ['deleted' => true]);
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error($response, 'forbidden_via_token', 'Domény lze spravovat jen z webového rozhraní.', 403);
        }
        if (!RequestAuthorization::allows($request, 'settings.domains', $level)) {
            return Json::error($response, 'forbidden_permission', 'Pro správu domén nemáš oprávnění.', 403);
        }
        return null;
    }

    private function supplierId(Request $request): int
    {
        $id = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($id < 1) throw new \DomainException('Firma není vybraná.');
        return $id;
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0);
    }

    /** @param array<string,mixed> $domain @return array<string,mixed> */
    private function present(array $domain): array
    {
        $hostname = (string) $domain['hostname'];
        $token = (string) $domain['verification_token'];
        $domain['dns'] = [
            'type' => 'TXT',
            'name' => '_myucto-challenge.' . $hostname,
            'value' => 'myucto-verification=' . $token,
        ];
        $domain['verification_url'] = 'https://' . $hostname . '/api/public/domain-verification/' . $token;
        // Karta aliasu ukazuje URL právě tohoto hostname. Efektivní doménu pro
        // nové odkazy vybírá TenantUrlResolver podle primárních flagů.
        $domain['portal_url'] = 'https://' . $hostname . '/portal';
        $domain['public_base_url'] = 'https://' . $hostname . '/';
        unset($domain['verification_token']);
        return $domain;
    }

    private function domainError(Response $response, \Throwable $e): Response
    {
        return match (true) {
            $e instanceof \OutOfBoundsException => Json::error($response, 'not_found', 'Doména nebyla nalezena.', 404),
            $e instanceof \DomainException => Json::error($response, 'domain_state_conflict', $e->getMessage(), 409),
            $e instanceof PDOException && $e->getCode() === '23000'
                => Json::error($response, 'domain_conflict', 'Primární doména pro tento účel už existuje.', 409),
            default => throw $e,
        };
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, int $domainId, array $payload): void
    {
        $this->activity->log(
            $action,
            $this->userId($request),
            'supplier_domain',
            $domainId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->supplierId($request),
        );
    }
}
