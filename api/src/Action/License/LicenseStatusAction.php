<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/license/status — přehled stavu licence (admin only).
 *
 * Vrací i objekt `company` s fakturačními údaji aktuální firmy (X-Supplier-Id) —
 * FE jimi předvyplní checkout na webu (nevynucené výchozí hodnoty).
 */
final class LicenseStatusAction
{
    public function __construct(
        private readonly LicenseService $license,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $state = LicenseMiddleware::state($request) ?? $this->license->current();
        $payload = $state->toArray($this->license->buyUrl());
        $payload['company'] = $this->company($request);

        return Json::ok($response, $payload);
    }

    /**
     * Fakturační údaje aktuální firmy (předvyplnění webového checkoutu). Fakturační
     * e-mail firmy; fallback na e-mail přihlášeného admina.
     *
     * @return array<string,string>
     */
    private function company(Request $request): array
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $adminEmail = trim((string) ($user['email'] ?? ''));

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $supplier = null;
        if ($supplierId > 0) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT company_name, ic, dic, street, city, zip, email FROM supplier WHERE id = ?'
            );
            $stmt->execute([$supplierId]);
            $supplier = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        $email = trim((string) ($supplier['email'] ?? ''));
        if ($email === '') {
            $email = $adminEmail;
        }

        return [
            'name'   => (string) ($supplier['company_name'] ?? ''),
            'ic'     => (string) ($supplier['ic'] ?? ''),
            'dic'    => (string) ($supplier['dic'] ?? ''),
            'street' => (string) ($supplier['street'] ?? ''),
            'city'   => (string) ($supplier['city'] ?? ''),
            'zip'    => (string) ($supplier['zip'] ?? ''),
            'email'  => $email,
        ];
    }
}
