<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Report\Section79Service;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * § 79 / § 79a ZDPH — odpočet při registraci a jeho snížení při zrušení registrace (ř. 45).
 *
 *   GET    /api/reports/s79?from=2026-01-01&to=2026-01-31  — READ-ONLY rozpis s částkami
 *   POST   /api/reports/s79 { kind, label, acquired_on, effective_on, asset_kind, vat_amount, … }
 *   DELETE /api/reports/s79/{id}
 *
 * Položky ZADÁVÁ účetní. Podmínku „je součástí obchodního majetku ke dni registrace"
 * (§ 79 odst. 1) systém z přijatých faktur nevidí — materiál mohl být spotřebován, zboží
 * prodáno. Systém ověřuje a dopočítává to, co z dat ověřit lze: lhůtu 12 měsíců a výši
 * snížení u dlouhodobého majetku (§ 79a odst. 2 → § 78d obdobně).
 *
 * ⚠️ Citlivý daňový výstup — pomůcka. Před podáním ověřit s účetní/poradcem.
 */
final class Section79Action
{
    public function __construct(
        private readonly Section79Service $service,
    ) {}

    /** GET rozpis položek za období — účetní|admin (reports READ). */
    public function list(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $from = trim((string) ($q['from'] ?? ''));
        $to = trim((string) ($q['to'] ?? ''));
        if (!self::isDate($from) || !self::isDate($to)) {
            return Json::error($response, 'validation_failed', 'Očekává se from a to ve tvaru Y-m-d.', 400);
        }
        if ($from > $to) {
            return Json::error($response, 'validation_failed', 'from nesmí být po to.', 400);
        }

        $rows = $this->service->preview($supplierId, $from, $to);

        return Json::ok($response, [
            'from'  => $from,
            'to'    => $to,
            'rows'  => $rows,
            // Součet je to, co půjde do ř. 45 — ať si ho volající nemusí dopočítávat sám
            // (a nedostal se k jinému číslu než přiznání).
            'total' => $this->service->totalForReturn($supplierId, $from, $to),
        ]);
    }

    /** POST zaevidování položky — mění daňovou evidenci, proto reports.finalize WRITE. */
    public function create(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.finalize', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0) ?: null;

        $body = (array) ($request->getParsedBody() ?? []);
        $acquiredOn = trim((string) ($body['acquired_on'] ?? ''));
        $effectiveOn = trim((string) ($body['effective_on'] ?? ''));
        if (!self::isDate($acquiredOn) || !self::isDate($effectiveOn)) {
            return Json::error($response, 'validation_failed', 'acquired_on a effective_on musí být Y-m-d.', 400);
        }
        $label = trim((string) ($body['label'] ?? ''));
        if ($label === '') {
            return Json::error($response, 'validation_failed', 'Popis majetku je povinný — bez něj nelze položku doložit.', 400);
        }

        $periodYears = isset($body['period_years']) && $body['period_years'] !== null && $body['period_years'] !== ''
            ? (int) $body['period_years']
            : null;

        try {
            $id = $this->service->register(
                $supplierId,
                (string) ($body['kind'] ?? ''),
                $label,
                $acquiredOn,
                $effectiveOn,
                (string) ($body['asset_kind'] ?? 'inventory'),
                (float) ($body['vat_amount'] ?? 0),
                $periodYears,
                [
                    'purchase_invoice_id' => isset($body['purchase_invoice_id']) ? (int) $body['purchase_invoice_id'] : null,
                    'asset_id'            => isset($body['asset_id']) ? (int) $body['asset_id'] : null,
                    'note'                => isset($body['note']) ? (string) $body['note'] : null,
                    'created_by'          => $userId,
                ],
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, ['id' => $id]);
    }

    /** DELETE položky — tatáž úroveň oprávnění jako zaevidování. */
    public function delete(Request $request, Response $response, array $args = []): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.finalize', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'validation_failed', 'Neplatné id.', 400);
        }

        $this->service->delete($supplierId, $id);

        return Json::ok($response, ['deleted' => $id]);
    }

    private static function isDate(string $v): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
    }
}
