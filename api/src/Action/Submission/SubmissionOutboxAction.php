<?php

declare(strict_types=1);

namespace MyInvoice\Action\Submission;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionCredentialService;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Fronta odchozích podání.
 *
 * Odesílat smí jen přihlášený člověk z webového rozhraní — proto stejný
 * `forbidden_via_token` guard jako u trezoru. Automat (cron, import) do fronty
 * zařazuje přes službu, ne přes tuhle akci, a odeslat nemůže vůbec.
 */
final class SubmissionOutboxAction
{
    public function __construct(
        private readonly SubmissionOutboxService $outbox,
        private readonly SubmissionCredentialService $credentials,
        private readonly ActivityLogger $logger,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $params = $request->getQueryParams();
        $environment = (string) ($params['environment'] ?? 'production');

        try {
            $items = $this->outbox->listForSupplier(SupplierGuard::currentId($request), $environment);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return Json::ok($response, ['items' => $items]);
    }

    /** @param array<string,string> $args */
    public function attempts(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }

        return Json::ok($response, [
            'items' => $this->outbox->attemptsFor(SupplierGuard::currentId($request), (int) ($args['id'] ?? 0)),
        ]);
    }

    public function enqueue(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $supplierId = SupplierGuard::currentId($request);

        try {
            $result = $this->outbox->enqueue(
                $supplierId,
                (string) ($body['environment'] ?? 'production'),
                (string) ($body['channel'] ?? 'isds'),
                (string) ($body['agenda_code'] ?? ''),
                (string) ($body['artifact_kind'] ?? ''),
                (int) ($body['artifact_id'] ?? 0),
                isset($body['recipient_id']) ? (int) $body['recipient_id'] : null,
                isset($body['subject']) ? (string) $body['subject'] : null,
                $this->userId($request),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'enqueue_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, $result);
    }

    /**
     * Potvrzení a odeslání člověkem.
     *
     * Opakované volání nevytvoří druhé podání — vrátí `dispatched: false`
     * a aktuální stav. Idempotenci drží podmínka `dispatch_state = 'ready'`
     * v UPDATE, ne kontrola v aplikaci.
     *
     * @param array<string,string> $args
     */
    public function confirm(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $environment = (string) ($body['environment'] ?? 'production');

        try {
            $context = $this->credentials->unlock($supplierId, $environment);
            $result = $this->outbox->confirmAndSend($supplierId, $id, $userId, $context);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'submission_conflict', $e->getMessage(), 409);
        }

        if ($result['dispatched']) {
            $this->logger->log('submission_outbox_sent', $userId, 'submission_outbox', $id, null, null, null, $supplierId);
        }

        return Json::ok($response, $result);
    }

    /**
     * Dořešení podání, u kterého se odeslání přerušilo.
     *
     * @param array<string,string> $args
     */
    public function resolve(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $context = $this->credentials->unlock($supplierId, (string) ($body['environment'] ?? 'production'));
            $row = $this->outbox->resolveUncertain($supplierId, (int) ($args['id'] ?? 0), $context);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'submission_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, $row);
    }

    /** @param array<string,string> $args */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        try {
            $row = $this->outbox->cancel(SupplierGuard::currentId($request), (int) ($args['id'] ?? 0));
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'submission_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, $row);
    }

    private function guard(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::allows($request, 'settings.signing', $level)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        if ($this->userId($request) <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'forbidden_via_token',
                'Podání se odesílá jen z webového rozhraní.',
                403,
            );
        }

        return null;
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        return (int) ($user['id'] ?? 0);
    }
}
