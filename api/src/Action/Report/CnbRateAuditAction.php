<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Currency\CnbRateDeviationChecker;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Dávkový audit „kurz na dokladu vs. ČNB" (§C / K4, private/REAL_data_followup_UX.md).
 *
 *   GET /api/reports/cnb-rate-audit?from=YYYY-MM-DD&to=YYYY-MM-DD&threshold=0.5
 *
 * Projde cizoměnové doklady (FV + PF) v rozsahu a vrátí ty, kde účetní kurz z hlavičky
 * odchýlen od denního ČNB kurzu k rozhodnému dni nad práh, seřazené dle dopadu v CZK.
 * Read-only report — NIKDY neopravuje kurzy. Pro firmu v pevném režimu (§24/7) se audit
 * přeskočí (`fixed_mode_skipped`).
 */
final class CnbRateAuditAction
{
    public function __construct(
        private readonly CnbRateDeviationChecker $checker,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);

        $q = $request->getQueryParams();
        $from = (string) ($q['from'] ?? '');
        $to   = (string) ($q['to'] ?? '');
        if (!self::isDate($from) || !self::isDate($to)) {
            $year = (int) date('Y');
            $from = $year . '-01-01';
            $to   = $year . '-12-31';
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $threshold = CnbRateDeviationChecker::DEFAULT_THRESHOLD_PERCENT;
        if (isset($q['threshold']) && is_numeric($q['threshold'])) {
            $t = (float) $q['threshold'];
            if ($t >= 0 && $t <= 100) {
                $threshold = $t;
            }
        }

        $result = $this->checker->findDeviations($supplierId, $from, $to, $threshold);

        return Json::ok($response, [
            'from'               => $from,
            'to'                 => $to,
            'threshold_percent'  => $threshold,
            'items'              => $result['items'],
            'missing_cnb_count'  => $result['missing_cnb_count'],
            'fixed_mode_skipped' => $result['fixed_mode_skipped'],
        ]);
    }

    private static function isDate(string $s): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return false;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $s);
        return $d !== false && $d->format('Y-m-d') === $s;
    }
}
