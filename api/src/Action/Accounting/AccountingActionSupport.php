<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\UnbalancedEntryException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Sdílené utility pro účetní REST API (Epic F1 API vrstva).
 *
 * RBAC zrcadlí PermissionMiddleware (defense-in-depth): zápisy vyžadují účetní|admin,
 * změna stavu období je admin-only. Autorizaci vynucuje i middleware podle cesty,
 * ale Action si ji ověří sám, aby byl přímo testovatelný a odolný vůči budoucí
 * změně routovacích pravidel.
 *
 * PostingException/UnbalancedEntryException se mapují na Json::error se strojovým
 * kódem a HTTP statusem (validace = 422), konzistentně s existujícím Json::error.
 */
trait AccountingActionSupport
{
    protected function currentSupplierId(Request $request): int
    {
        return SupplierGuard::currentId($request);
    }

    protected function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $id = (int) ($user['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** Zápis (účetní data): účetní nebo admin. */
    protected function requireWrite(Request $request, Response $response, ?Response &$err): bool
    {
        return $this->requirePermission($request, $response, 'accounting', AccessLevel::WRITE, $err);
    }

    /** Správa stavu období (schválení / reopen / review — změna stavu období). */
    protected function requireAdmin(Request $request, Response $response, ?Response &$err): bool
    {
        return $this->requirePermission($request, $response, 'accounting.periods.manage', AccessLevel::WRITE, $err);
    }

    /**
     * Uzávěrkový workflow (start/kroky/close/open-next/revert). Sjednocuje Action
     * defense-in-depth s RoutePermissionMap: middleware gatuje celou rodinu
     * /periods/{id}/(closing|close|open-next|revert) na 'accounting.periods.close',
     * proto ji Action ověřuje týmž právem (ne 'accounting' ani 'accounting.periods.manage').
     */
    protected function requireClose(Request $request, Response $response, ?Response &$err): bool
    {
        return $this->requirePermission($request, $response, 'accounting.periods.close', AccessLevel::WRITE, $err);
    }

    /**
     * Pokladní doklady (PPD/VPD). Zrcadlí RoutePermissionMap, která celou rodinu
     * /api/accounting/cash-documents gatuje na 'cash.document.write' — Action proto
     * ověřuje TOTÉŽ právo. Na 'accounting' WRITE se ptát nesmí: `EffectiveRole::level()`
     * je plochý katalog bez hierarchie, takže dedikovaná role „Pokladní" (cash +
     * cash.document.write) prošla middlewarem a spadla na 403 až v Action.
     */
    protected function requireCashDocumentWrite(Request $request, Response $response, ?Response &$err): bool
    {
        return $this->requirePermission($request, $response, 'cash.document.write', AccessLevel::WRITE, $err);
    }

    /** Správa pokladen (číselník) — modul 'cash', shodně s RoutePermissionMap. */
    protected function requireCashWrite(Request $request, Response $response, ?Response &$err): bool
    {
        return $this->requirePermission($request, $response, 'cash', AccessLevel::WRITE, $err);
    }

    /**
     * Uzavření/uzamčení pokladny a TVRDÉ smazání zaúčtovaného dokladu (`?force=1`).
     * Destrukce s účetními dopady (mizí deníkové zápisy, v číselné řadě zůstane díra)
     * proto nestačí běžné zápisové právo — zrcadlo `accounting.journal.post`.
     */
    protected function requireCashClose(Request $request, Response $response, ?Response &$err): bool
    {
        return $this->requirePermission($request, $response, 'cash.close', AccessLevel::WRITE, $err);
    }

    protected function requirePermission(Request $request, Response $response, string $permission, AccessLevel $minimum, ?Response &$err): bool
    {
        if (!RequestAuthorization::allows($request, $permission, $minimum)) {
            $err = Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
            return false;
        }
        $err = null;
        return true;
    }

    /**
     * Audit metadata pro PostingService::postDocument/reverse (kdo + odkud).
     *
     * @return array{user_id:?int, posted_by:?int, ip:?string, user_agent:?string}
     */
    protected function auditMeta(Request $request): array
    {
        $uid = $this->userId($request);
        return [
            'user_id'    => $uid,
            'posted_by'  => $uid,
            'ip'         => $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            'user_agent' => $request->getHeaderLine('User-Agent'),
        ];
    }

    /**
     * Mapuje účetní výjimky na Json::error. UnbalancedEntry i PostingException jsou
     * validační (422 default), s výjimkou 404 (neexistující doklad/zápis).
     */
    protected function mapPostingError(Response $response, \Throwable $e): Response
    {
        if ($e instanceof UnbalancedEntryException) {
            return Json::error($response, 'unbalanced_entry', $e->getMessage(), 422, [
                'debit'  => $e->debitCents / 100,
                'credit' => $e->creditCents / 100,
            ]);
        }
        if ($e instanceof PostingException) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        throw $e;
    }
}
