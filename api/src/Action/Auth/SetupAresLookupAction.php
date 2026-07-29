<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ares\AresClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/auth/setup-ares-lookup  body: { ic: string }
 *
 * Public ARES proxy pro setup wizard — funguje POUZE pokud aplikace ještě
 * nemá admin uživatele (tj. během prvotního setupu). Po dokončení setupu
 * vrací 403 a klient musí použít autentizovaný `/api/clients/lookup-ares`.
 *
 * Cílem je umožnit ARES auto-fill v setup formuláři, kdy ještě není možná
 * běžná auth session (FirstRunLockMiddleware blokuje všechno mimo setup-* endpointy).
 */
final class SetupAresLookupAction
{
    public function __construct(
        private readonly AresClient $ares,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        // Validace „setup phase" — musí být 0 admin userů
        $adminCount = (int) $this->db->pdo()
            ->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
                      WHERE r.system_key = 'superadmin' AND r.role_type = 'superadmin'
                        AND r.is_active = 1 AND u.is_active = 1")
            ->fetchColumn();
        if ($adminCount > 0) {
            return Json::error($response, 'setup_done', 'Setup je dokončený, použij /api/clients/lookup-ares.', 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $ic = preg_replace('/\D/', '', (string) ($body['ic'] ?? '')) ?? '';
        if (strlen($ic) !== 8) {
            return Json::error($response, 'invalid_ic', 'IČO musí mít 8 číslic.', 400);
        }

        $result = $this->ares->lookup($ic);
        if ($result === null) {
            return Json::error($response, 'ares_unavailable', 'ARES je dočasně nedostupný.', 503);
        }
        return Json::ok($response, $result);
    }
}
