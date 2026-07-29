<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Nastavení výkaznictví per firma (Epic F2, R10/R11; Epic F4 R11/R13/R18) —
 * průměrný počet zaměstnanců (ruční vstup), override rozsahu výkazů a přepínače
 * uzávěrky: povinný audit §20 ZoÚ (statutory_audit → scope 'full' dle §3a vyhl.),
 * opt-in číselná řada ID pro ruční zápisy (manual_doc_series) a FX storno
 * saldokonta k 1. dni nového období (fx_reversal_at_open).
 *
 *   GET /api/accounting/reporting-settings — čtení (readonly+)
 *   PUT /api/accounting/reporting-settings — zápis — účetní|admin
 *
 * Přepínače se ukládají jen jsou-li v body přítomné (partial update — starší
 * FE klienti bez těchto klíčů je nepřepíšou).
 */
final class ReportingSettingsAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const FLAG_KEYS = ['statutory_audit', 'manual_doc_series', 'fx_reversal_at_open'];

    public function __construct(
        private readonly AccountingSupplierSettingsRepository $settings,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        return Json::ok($response, $this->settings->get($supplierId));
    }

    public function update(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);

        $avgEmployees = $body['avg_employees'] ?? null;
        if ($avgEmployees !== null && $avgEmployees !== '') {
            if (!is_numeric($avgEmployees) || (int) $avgEmployees != $avgEmployees || (int) $avgEmployees < 0) {
                return Json::error($response, 'validation_failed', 'avg_employees musí být celé číslo ≥ 0, nebo null.', 422);
            }
            $avgEmployees = (int) $avgEmployees;
        } else {
            $avgEmployees = null;
        }

        $scopeOverride = $body['statement_scope_override'] ?? null;
        if ($scopeOverride !== null && $scopeOverride !== '') {
            $scopeOverride = (string) $scopeOverride;
            if (!in_array($scopeOverride, ['full', 'small', 'micro'], true)) {
                return Json::error($response, 'validation_failed', "statement_scope_override musí být 'full', 'small', 'micro', nebo null.", 422);
            }
        } else {
            $scopeOverride = null;
        }

        // F4 flagy: null = klíč v body chybí → hodnota se nemění (partial update).
        $flags = ['statutory_audit' => null, 'manual_doc_series' => null, 'fx_reversal_at_open' => null];
        foreach (self::FLAG_KEYS as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $v = filter_var($body[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($v === null) {
                return Json::error($response, 'validation_failed', "{$key} musí být boolean (true/false).", 422);
            }
            $flags[$key] = $v;
        }

        $this->settings->upsert($supplierId, $avgEmployees, $scopeOverride,
            $flags['statutory_audit'], $flags['manual_doc_series'], $flags['fx_reversal_at_open']);

        // §DM / Task 14: účetní politika časového rozlišení drobného majetku na 381
        // (§7 ZoÚ, volitelná). Partial update — ukládá se jen je-li mode v body.
        if (array_key_exists('small_asset_accrual_mode', $body)) {
            $mode = (string) $body['small_asset_accrual_mode'];
            if (!in_array($mode, ['none', 'pro_rata', 'flat_pct'], true)) {
                return Json::error($response, 'validation_failed', "small_asset_accrual_mode musí být 'none', 'pro_rata' nebo 'flat_pct'.", 422);
            }
            $pct = null;
            if ($mode === 'flat_pct') {
                $rawPct = $body['small_asset_accrual_pct'] ?? null;
                if (!is_numeric($rawPct)) {
                    return Json::error($response, 'validation_failed', 'small_asset_accrual_pct je u režimu flat_pct povinné (0–100).', 422);
                }
                $pct = (float) $rawPct;
                if ($pct < 0 || $pct > 100) {
                    return Json::error($response, 'validation_failed', 'small_asset_accrual_pct musí být v rozsahu 0–100.', 422);
                }
            }
            $this->settings->setSmallAssetAccrual($supplierId, $mode, $pct);
        }

        return Json::ok($response, $this->settings->get($supplierId));
    }
}
