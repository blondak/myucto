<?php

declare(strict_types=1);

namespace MyInvoice\Action\Submission;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionCredentialService;
use MyInvoice\Service\Submission\SubmissionInboxService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Příchozí zprávy z datové schránky.
 *
 * `state` v odpovědi nese `last_ok_at` odděleně od `last_attempt_at` — UI má
 * podle čeho poznat, že „0 zpráv" znamená prázdnou schránku, a ne že se na ni
 * aplikace nedovolá.
 */
final class SubmissionInboxAction
{
    public function __construct(
        private readonly SubmissionInboxService $inbox,
        private readonly SubmissionCredentialService $credentials,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $params = $request->getQueryParams();
        $environment = (string) ($params['environment'] ?? 'production');
        $classification = isset($params['classification']) && $params['classification'] !== ''
            ? (string) $params['classification']
            : null;

        return Json::ok($response, [
            'items' => $this->inbox->listRecent($supplierId, $environment, $classification),
            'state' => $this->inbox->pollState($supplierId, 'isds', $environment),
        ]);
    }

    /** Ruční vyzvednutí — tatáž cesta jako cron, jen spuštěná uživatelem. */
    public function poll(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $environment = (string) ($body['environment'] ?? 'production');

        try {
            $context = $this->credentials->unlock($supplierId, $environment);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        $result = $this->inbox->poll($context, 'isds');
        if ($result['error'] !== null && $result['fetched'] === 0) {
            // Neúspěšný dotaz se nesmí uživateli ukázat jako „nic nového".
            return Json::error(
                $response,
                (string) $result['error'],
                'Na datovou schránku se nepodařilo dovolat, takže o nových zprávách nic nevíme. Zkuste to prosím znovu.',
                502,
            );
        }

        return Json::ok($response, $result);
    }

    /** @param array<string,string> $args */
    public function reclassify(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $updated = $this->inbox->reclassify(
                SupplierGuard::currentId($request),
                (int) ($args['id'] ?? 0),
                (string) ($body['classification'] ?? ''),
                isset($body['outbox_id']) ? (int) $body['outbox_id'] : null,
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        if (!$updated) {
            return Json::error($response, 'not_found', 'Zpráva nebyla nalezena.', 404);
        }

        return Json::ok($response, ['updated' => true]);
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
                'Datová schránka se obsluhuje jen z webového rozhraní.',
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
