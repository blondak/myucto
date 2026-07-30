<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Update\VersionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin endpointy pro správu upgradu:
 *   GET  /api/admin/update/status     — plný stav (s release notes)
 *   GET  /api/admin/update/preflight  — může nativní instalace updatovat sama?
 *   POST /api/admin/update/refresh    — fresh fetch z GitHub Releases API
 *   POST /api/admin/update/trigger    — zařadit upgrade do fronty (Docker)
 *                                       nebo spustit nativní worker
 */
final class UpdateAction
{
    public function __construct(
        private readonly VersionService $version,
        private readonly ActivityLogger $logger,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err)) return $err;
        return Json::ok($response, $this->version->getStatus());
    }

    /**
     * Preflight nativního upgradu. Samostatný endpoint (ne součást `status`),
     * protože zapisuje probe soubory pro kontrolu práv — nemá běžet při
     * každém 5s pollu.
     */
    public function preflight(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err)) return $err;
        $target = $request->getQueryParams()['target_version'] ?? null;
        return Json::ok($response, $this->version->nativePreflight(
            is_string($target) && $target !== '' ? $target : null
        ));
    }

    public function refresh(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err)) return $err;
        $status = $this->version->refreshLatestVersion();
        return Json::ok($response, $status);
    }

    public function trigger(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err)) return $err;
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $body = (array) ($request->getParsedBody() ?? []);
        $target = isset($body['target_version']) ? (string) $body['target_version'] : null;

        $result = $this->version->triggerUpgrade($target, (string) ($user['email'] ?? 'unknown'));

        $this->logger->log(
            'system.upgrade.trigger',
            (int) ($user['id'] ?? 0),
            null,
            null,
            [
                'environment'    => $result['environment'] ?? null,
                'target_version' => $result['target_version'] ?? null,
                'status'         => $result['status'] ?? null,
            ]
        );

        return Json::ok($response, $result);
    }

    public function cancel(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err)) return $err;
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $result = $this->version->cancelUpgrade();
        $this->logger->log('system.upgrade.cancel', (int) ($user['id'] ?? 0), null, null, [
            'cleared' => $result['cleared'] ?? false,
        ]);
        return Json::ok($response, $result);
    }

    private function isAdmin(Request $request, Response $response, ?Response &$err): bool
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            $err = Json::error($response, 'forbidden', 'Pouze admin.', 403);
            return false;
        }
        $err = null;
        return true;
    }
}
