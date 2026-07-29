<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Správa měkkého zámku účtování k datu (B8, audit 2026-07 core-posting) —
 * accounting_supplier_settings.locked_until. Doklady s entry_date <= locked_until
 * backend odmítne zaúčtovat/re-postnout (PostingService, 'date_locked'); storno je
 * možné jen s protizápisem do otevřeného data.
 *
 *   GET /api/accounting/period-lock — čtení (readonly+)
 *   PUT /api/accounting/period-lock — nastavení/posun zámku — ADMIN only, povinný reason
 *
 * Auto-posun zámku VPŘED dělá i {@see \MyInvoice\Service\Report\TaxSubmissionArchiver}
 * po archivaci DPH přiznání (VAT-lock/H7). Tento endpoint umožní zámek posunout ručně
 * i ZPĚT (oprava už podaného období) nebo zrušit (locked_until = null) — proto admin-only
 * + povinné zdůvodnění do activity_log (before/after + reason).
 */
final class PeriodLockAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const MIN_REASON_LEN = 5;

    public function __construct(
        private readonly AccountingSupplierSettingsRepository $settings,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        return Json::ok($response, ['locked_until' => $this->settings->getLockedUntil($supplierId)]);
    }

    public function update(Request $request, Response $response): Response
    {
        if (!$this->requireAdmin($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);

        $raw = $body['locked_until'] ?? null;
        $lockedUntil = null;
        if ($raw !== null && $raw !== '') {
            $lockedUntil = (string) $raw;
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $lockedUntil);
            if ($d === false || $d->format('Y-m-d') !== $lockedUntil) {
                return Json::error($response, 'validation_failed', 'locked_until musí být datum ve formátu YYYY-MM-DD, nebo null.', 422);
            }
        }

        $reason = trim((string) ($body['reason'] ?? ''));
        if (mb_strlen($reason) < self::MIN_REASON_LEN) {
            return Json::error($response, 'validation_failed', 'Zdůvodnění změny zámku je povinné (min. ' . self::MIN_REASON_LEN . ' znaků).', 422);
        }

        $before = $this->settings->getLockedUntil($supplierId);
        $this->settings->setLockedUntil($supplierId, $lockedUntil);

        $this->activity->log(
            'accounting.lock_date_changed',
            $this->userId($request),
            'supplier',
            $supplierId,
            ['before' => $before, 'after' => $lockedUntil, 'reason' => $reason],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, ['locked_until' => $lockedUntil]);
    }
}
