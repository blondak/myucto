<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Tax\RelatedPartyService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * § 36a ZDPH + § 23 odst. 7 ZDP — spojené osoby, ceny obvyklé a úprava základu daně.
 *
 *   GET    /api/reports/related-parties?from&to   — transakce + měřitelné cenové odchylky
 *   GET    /api/reports/related-parties/adjustments?year — úpravy základu daně § 23/7
 *   POST   /api/reports/related-parties/adjustments      — zaevidování úpravy
 *   DELETE /api/reports/related-parties/adjustments/{id}
 *
 * Odchylka se hlásí jen tam, kde má systém srovnání — tedy když totéž prodával
 * i nespojeným osobám. „Cena obvyklá" jinak není veličina, kterou by účetní systém znal,
 * a doložit ji je na účetní.
 *
 * ⚠️ Citlivý daňový výstup — pomůcka. Před podáním ověřit s účetní/poradcem.
 */
final class RelatedPartyAction
{
    public function __construct(
        private readonly RelatedPartyService $service,
    ) {}

    /** GET transakce se spojenými osobami + cenové odchylky za období. */
    public function overview(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $from = trim((string) ($q['from'] ?? ''));
        $to = trim((string) ($q['to'] ?? ''));
        if (!self::isDate($from) || !self::isDate($to) || $from > $to) {
            return Json::error($response, 'validation_failed', 'Očekává se from a to (Y-m-d), from ≤ to.', 400);
        }

        $transactions = $this->service->transactions($supplierId, $from, $to);

        return Json::ok($response, [
            'from'         => $from,
            'to'           => $to,
            'transactions' => $transactions,
            'total'        => round(array_sum(array_column($transactions, 'amount')), 2),
            'deviations'   => $this->service->priceDeviations($supplierId, $from, $to),
        ]);
    }

    /** GET úpravy základu daně § 23/7 za rok. */
    public function adjustments(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $year = (int) ($request->getQueryParams()['year'] ?? 0);
        if ($year < 2000 || $year > 2100) {
            return Json::error($response, 'validation_failed', 'Neplatný rok.', 400);
        }

        return Json::ok($response, $this->service->adjustments($supplierId, $year));
    }

    /** POST zaevidování úpravy — mění daňový základ, proto reports.finalize WRITE. */
    public function createAdjustment(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.finalize', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0) ?: null;

        $body = (array) ($request->getParsedBody() ?? []);
        $year = (int) ($body['fiscal_year'] ?? 0);
        if ($year < 2000 || $year > 2100) {
            return Json::error($response, 'validation_failed', 'Neplatný fiscal_year.', 400);
        }

        try {
            $id = $this->service->recordAdjustment(
                $supplierId,
                $year,
                (float) ($body['amount'] ?? 0),
                (string) ($body['reason'] ?? ''),
                isset($body['client_id']) && $body['client_id'] !== null ? (int) $body['client_id'] : null,
                (string) ($body['movement'] ?? 'increase'),
                $userId,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, ['id' => $id]);
    }

    public function deleteAdjustment(Request $request, Response $response, array $args = []): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.finalize', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'validation_failed', 'Neplatné id.', 400);
        }

        $this->service->deleteAdjustment(SupplierGuard::currentId($request), $id);

        return Json::ok($response, ['deleted' => $id]);
    }

    private static function isDate(string $v): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
    }
}
