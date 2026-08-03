<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

trait PayrollActionSupport
{
    private function currentSupplierId(Request $request): int
    {
        return SupplierGuard::currentId($request);
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $id = (int) ($user['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private function requirePermission(
        Request $request,
        Response $response,
        string $permission,
        AccessLevel $minimum,
        ?Response &$error,
    ): bool {
        if (!RequestAuthorization::allows($request, $permission, $minimum)) {
            $error = Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
            return false;
        }
        $error = null;
        return true;
    }

    private function requirePayrollEnabled(
        Request $request,
        Response $response,
        PayrollModuleAccess $access,
        ?Response &$error,
    ): bool {
        if (!$access->isEnabled($this->currentSupplierId($request))) {
            $error = Json::error(
                $response,
                'payroll_disabled',
                'Vedení mezd je pro tuto firmu vypnuté v nastavení.',
                403,
            );
            return false;
        }
        $error = null;
        return true;
    }
}
