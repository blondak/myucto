<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\NetTurnoverSuggestionService;
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
 *   GET /api/accounting/reporting-settings/net-turnover-hints — nezávazný
 *       podklad k výnosům obchodního modelu (readonly+)
 *
 * Nápověda stojí ve vlastní routě schválně: kvůli obratům sestavuje celý výkaz
 * zisku a ztráty, což je příliš drahé na to, aby to platilo každé čtení
 * nastavení.
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
        private readonly NetTurnoverSuggestionService $netTurnover,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        return Json::ok($response, $this->payload($supplierId));
    }

    /**
     * Nastavení + volby řádků pro výnosy obchodního modelu (§ 35 vyhl. 500/2002 Sb.)
     * s popisky z definice výkazu, ať je UI nemusí držet ve vlastní kopii.
     *
     * @return array<string,mixed>
     */
    private function payload(int $supplierId): array
    {
        $options = [];
        foreach (FinancialStatementService::NET_TURNOVER_EXTRA_ROW_OPTIONS as $type => $codes) {
            $placeholders = implode(',', array_fill(0, count($codes), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT sr.row_code, sr.label
                   FROM statement_rows sr
                   JOIN statement_versions sv ON sv.id = sr.version_id
                  WHERE sv.statement_type = ? AND sv.valid_to IS NULL AND sr.row_code IN ({$placeholders})"
            );
            $stmt->execute([$type, ...$codes]);
            $labels = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $labels[(string) $r['row_code']] = (string) $r['label'];
            }
            foreach ($codes as $code) {
                $options[$type][] = ['code' => $code, 'label' => $labels[$code] ?? $code];
            }
        }
        return $this->settings->get($supplierId) + ['net_turnover_extra_row_options' => $options];
    }

    /**
     * Nezávazný podklad k výběru výnosů obchodního modelu: návrh podle CZ-NACE
     * a obraty jednotlivých řádků. Nic nenastavuje — zaškrtnutí i uložení
     * zůstává na účetní, protože je to úsudek účetní jednotky, který se podle
     * § 1a odst. 2 ZoÚ uvádí v příloze v účetní závěrce.
     */
    public function netTurnoverHints(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        return Json::ok($response, $this->netTurnover->hints($supplierId));
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

        // § 35 vyhl. 500/2002 Sb.: řádky VZZ, které firma počítá k výnosům obchodního
        // modelu (čistý obrat nad I. + II.). Partial update — jen je-li klíč v body.
        if (array_key_exists('net_turnover_extra_rows', $body)) {
            $raw = $body['net_turnover_extra_rows'];
            if (!is_array($raw)) {
                return Json::error($response, 'validation_failed', 'net_turnover_extra_rows musí být objekt se seznamy řádků.', 422);
            }
            $clean = [];
            foreach (FinancialStatementService::NET_TURNOVER_EXTRA_ROW_OPTIONS as $type => $allowed) {
                $list = $raw[$type] ?? [];
                if (!is_array($list)) {
                    return Json::error($response, 'validation_failed', "net_turnover_extra_rows.{$type} musí být seznam řádků.", 422);
                }
                foreach ($list as $code) {
                    if (!is_string($code) || !in_array($code, $allowed, true)) {
                        return Json::error($response, 'validation_failed', "Řádek {$type} „" . (is_scalar($code) ? (string) $code : '?') . '" do čistého obratu přičíst nelze.', 422);
                    }
                }
                $clean[$type] = array_values(array_unique($list));
            }
            $this->settings->setNetTurnoverExtraRows($supplierId, $clean);
        }

        return Json::ok($response, $this->payload($supplierId));
    }
}
