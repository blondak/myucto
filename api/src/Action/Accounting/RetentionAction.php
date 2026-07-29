<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\RetentionHoldRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\RetentionGuard;
use MyInvoice\Service\ActivityLogger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Retenční lhůty účetních a daňových záznamů — § 31/§ 32 ZoÚ, § 35a ZDPH.
 *
 *   GET    /api/accounting/retention              — přehled období a lhůt
 *   GET    /api/accounting/retention/holds        — zadržení podle § 32
 *   POST   /api/accounting/retention/holds        — zadržet (daňová kontrola, spor…)
 *   DELETE /api/accounting/retention/holds/{id}   — uvolnit
 *
 * Přehled je čistě informativní: uplynulá lhůta je KONEC POVINNOSTI uchovávat, ne pokyn
 * ke skartaci. Žádný endpoint nic nemaže.
 */
final class RetentionAction
{
    private const REASONS = ['tax_audit', 'appeal', 'litigation', 'other'];

    public function __construct(
        private readonly RetentionGuard $guard,
        private readonly RetentionHoldRepository $holds,
        private readonly ActivityLogger $logger,
    ) {}

    public function overview(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);

        return Json::ok($response, ['periods' => $this->guard->overview($supplierId)]);
    }

    public function listHolds(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $includeReleased = ($request->getQueryParams()['include_released'] ?? '') === '1';

        return Json::ok($response, ['holds' => $this->holds->all($supplierId, $includeReleased)]);
    }

    public function placeHold(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0) ?: null;

        $body = (array) ($request->getParsedBody() ?? []);
        $reason = trim((string) ($body['reason'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $periodYear = isset($body['period_year']) && $body['period_year'] !== null && $body['period_year'] !== ''
            ? (int) $body['period_year']
            : null;
        $placedOn = trim((string) ($body['placed_on'] ?? date('Y-m-d')));

        if (!in_array($reason, self::REASONS, true)) {
            return Json::error($response, 'validation_failed',
                'reason musí být jedna z: ' . implode(', ', self::REASONS) . '.', 400);
        }
        if ($description === '') {
            return Json::error($response, 'validation_failed',
                'Vyplňte č. j. nebo popis řízení — bez něj nelze doložit, proč jsou záznamy zadržené.', 400);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $placedOn) !== 1) {
            return Json::error($response, 'validation_failed', 'placed_on musí být ve tvaru Y-m-d.', 400);
        }
        if ($periodYear !== null && ($periodYear < 2000 || $periodYear > 2100)) {
            return Json::error($response, 'validation_failed', 'Neplatný rok období.', 400);
        }

        $id = $this->holds->place($supplierId, $periodYear, $reason, mb_substr($description, 0, 255), $placedOn, $userId);
        $this->logger->log('accounting.retention_hold_placed', $userId, 'retention_hold', $id, [
            'period_year' => $periodYear,
            'reason'      => $reason,
            'description' => $description,
        ]);

        return Json::ok($response, ['id' => $id]);
    }

    public function releaseHold(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0) ?: null;
        $id = (int) ($args['id'] ?? 0);

        if (!$this->holds->release($supplierId, $id, date('Y-m-d'), $userId)) {
            return Json::error($response, 'not_found', 'Aktivní zadržení nenalezeno.', 404);
        }
        $this->logger->log('accounting.retention_hold_released', $userId, 'retention_hold', $id, []);

        return Json::ok($response, ['ok' => true]);
    }
}
