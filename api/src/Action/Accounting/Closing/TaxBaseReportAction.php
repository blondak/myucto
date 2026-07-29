<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Closing;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Evidenční podklad DPPO (Epic F4, R19) — ŽÁDNÉ účtování, žádné XML; budoucí
 * vstup účetní větve IncomeTaxBuilderu.
 *
 *   GET /api/accounting/reports/tax-base-adjustments?fiscal_year=YYYY
 *
 * Vrací: (a) rozdíl daňových a účetních odpisů roku (úprava základu daně),
 * (b) vyřazený majetek roku s daňovou/účetní zůstatkovou cenou a klasifikací
 * daňové uznatelnosti ZC (§24/2/b, §25/1/o, §24/2/c+l ZDP), (c) informativní
 * zůstatky dohadných účtů 388/389 a kurzové rozdíly 563/663 z přecenění.
 */
final class TaxBaseReportAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        private readonly LoggerInterface $log,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $fiscalYear = (int) ($request->getQueryParams()['fiscal_year'] ?? 0);
        if ($fiscalYear < 2000 || $fiscalYear > 2200) {
            return Json::error($response, 'validation_failed', 'fiscal_year je povinný (rozumný účetní rok).', 422);
        }

        $period = $this->periods->findByYear($supplierId, $fiscalYear);
        if ($period === null) {
            return Json::error($response, 'not_found', 'Účetní období pro daný rok neexistuje.', 404);
        }

        try {
            $data = [
                'fiscal_year'  => $fiscalYear,
                'period'       => [
                    'id'        => (int) $period['id'],
                    'starts_on' => (string) $period['starts_on'],
                    'ends_on'   => (string) $period['ends_on'],
                ],
                'depreciation' => $this->depreciationDiff($supplierId, $fiscalYear),
                'disposals'    => $this->disposals($supplierId, $period),
                'info'         => $this->infoBalances($supplierId, $period),
                'note'         => 'Evidenční podklad pro přiznání DPPO — systém nic neúčtuje; splatná daň '
                    . '(MD 591 / D 341) se účtuje ručním zápisem.',
            ];
        } catch (\Throwable $e) {
            $this->log->error('Podklad DPPO se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'operation_failed', 'Podklad se nepodařilo sestavit.', 500);
        }

        return Json::ok($response, $data);
    }

    /**
     * (a) Σ daňových vs. účetních odpisů roku. Kladný rozdíl (daňové > účetní)
     * = snížení základu daně, záporný = zvýšení (§24/2/a, §23/3 ZDP).
     *
     * @return array{tax_total:float, accounting_total:float, difference:float, note:string}
     */
    private function depreciationDiff(int $supplierId, int $fiscalYear): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT kind, COALESCE(SUM(amount), 0) AS total
               FROM depreciation_entries
              WHERE supplier_id = ? AND fiscal_year = ?
              GROUP BY kind'
        );
        $stmt->execute([$supplierId, $fiscalYear]);
        $sums = ['tax' => 0.0, 'accounting' => 0.0];
        foreach ($stmt->fetchAll() as $row) {
            $sums[(string) $row['kind']] = (float) $row['total'];
        }
        $diff = round($sums['tax'] - $sums['accounting'], 2);

        return [
            'tax_total'        => round($sums['tax'], 2),
            'accounting_total' => round($sums['accounting'], 2),
            'difference'       => $diff,
            'note'             => $diff >= 0
                ? 'Daňové odpisy převyšují účetní o ' . number_format($diff, 2, ',', ' ') . ' Kč — základ daně se snižuje.'
                : 'Účetní odpisy převyšují daňové o ' . number_format(-$diff, 2, ',', ' ') . ' Kč — základ daně se zvyšuje.',
        ];
    }

    /**
     * (b) Majetek vyřazený v období: daňová ZC (poslední daňový odpisový řádek,
     * fallback vstupní cena + TZ − opening), účetní ZC z deníku (debetní 5xx řádky
     * zápisu asset_disposal) a klasifikace daňové uznatelnosti ZC dle ZDP.
     *
     * @return list<array<string,mixed>>
     */
    private function disposals(int $supplierId, array $period): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.inventory_number, a.name, a.disposal_date, a.disposal_type, a.disposal_price,
                    a.input_price, a.opening_tax_amount,
                    (SELECT COALESCE(SUM(ai.amount), 0) FROM asset_improvements ai
                      WHERE ai.supplier_id = a.supplier_id AND ai.asset_id = a.id) AS improvements_total,
                    (SELECT de.residual_value_end FROM depreciation_entries de
                      WHERE de.supplier_id = a.supplier_id AND de.asset_id = a.id AND de.kind = \'tax\'
                      ORDER BY de.fiscal_year DESC LIMIT 1) AS tax_residual,
                    (SELECT de.residual_value_end FROM depreciation_entries de
                      WHERE de.supplier_id = a.supplier_id AND de.asset_id = a.id AND de.kind = \'accounting\'
                      ORDER BY de.fiscal_year DESC LIMIT 1) AS acc_residual
               FROM assets a
              WHERE a.supplier_id = ? AND a.status = \'disposed\'
                AND a.disposal_date BETWEEN ? AND ?
              ORDER BY a.disposal_date, a.inventory_number'
        );
        $stmt->execute([$supplierId, (string) $period['starts_on'], (string) $period['ends_on']]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $taxResidual = $row['tax_residual'] !== null
                ? (float) $row['tax_residual']
                : max(0.0, (float) $row['input_price'] + (float) $row['improvements_total'] - (float) $row['opening_tax_amount']);
            $accResidual = $this->accountingResidualFromJournal($supplierId, (int) $row['id']);
            if ($accResidual === null) {
                $accResidual = $row['acc_residual'] !== null ? (float) $row['acc_residual'] : null;
            }
            [$deductibility, $note] = $this->classifyDisposal((string) $row['disposal_type']);

            $out[] = [
                'asset_id'                  => (int) $row['id'],
                'inventory_number'          => (string) $row['inventory_number'],
                'name'                      => (string) $row['name'],
                'disposal_date'             => (string) $row['disposal_date'],
                'disposal_type'             => (string) $row['disposal_type'],
                'disposal_price'            => $row['disposal_price'] !== null ? (float) $row['disposal_price'] : null,
                'tax_residual_value'        => round($taxResidual, 2),
                'accounting_residual_value' => $accResidual !== null ? round($accResidual, 2) : null,
                'deductibility'             => $deductibility,
                'note'                      => $note,
            ];
        }
        return $out;
    }

    /** Účetní ZC z deníku = Σ debetních řádků na 5xx v posted zápisu vyřazení. */
    private function accountingResidualFromJournal(int $supplierId, int $assetId): ?float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT SUM(l.amount) AS zc
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
              WHERE l.supplier_id = ? AND e.supplier_id = ?
                AND e.source_type = \'asset_disposal\' AND e.source_id = ?
                AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                AND l.side = \'debit\' AND ca.account_code LIKE \'5%\''
        );
        $stmt->execute([$supplierId, $supplierId, $assetId]);
        $zc = $stmt->fetchColumn();
        return $zc === null || $zc === false ? null : (float) $zc;
    }

    /**
     * Klasifikace daňové uznatelnosti daňové ZC při vyřazení (R19).
     *
     * @return array{0:'full'|'none'|'limited', 1:string}
     */
    private function classifyDisposal(string $type): array
    {
        return match ($type) {
            'sold'       => ['full', 'Prodej — daňová ZC plně uznatelná (§24 odst. 2 písm. b) ZDP).'],
            'liquidated' => ['full', 'Likvidace — daňová ZC plně uznatelná (§24 odst. 2 písm. b) ZDP; výjimka: likvidace stavebního díla v souvislosti s novou výstavbou — ZC vstupuje do ceny nové stavby, bod 2).'],
            'donated'    => ['none', 'Dar — daňová ZC neuznatelná (§25 odst. 1 písm. o) ZDP).'],
            'damaged'    => ['limited', 'Škoda — daňová ZC uznatelná jen do výše přijatých náhrad '
                . '(§24 odst. 2 písm. c) ZDP); plně u živelní pohromy nebo škody způsobené dle potvrzení policie neznámým pachatelem (§24 odst. 2 písm. l) ZDP).'],
            default      => ['limited', 'Neznámý typ vyřazení — posuďte individuálně.'],
        };
    }

    /**
     * (c) Informativní zůstatky: dohadné účty 388/389 ke konci období a Σ 563/663
     * ze zápisů přecenění (source_type fx_revaluation) daného období.
     *
     * @return array<string,float>
     */
    private function infoBalances(int $supplierId, array $period): array
    {
        $endsOn = (string) $period['ends_on'];
        $periodId = (int) $period['id'];

        return [
            'estimates_388_balance' => $this->accountBalance($supplierId, '388', $endsOn),
            'estimates_389_balance' => $this->accountBalance($supplierId, '389', $endsOn),
            'fx_revaluation_loss_563' => $this->fxTotal($supplierId, $periodId, '563', 'debit'),
            'fx_revaluation_gain_663' => $this->fxTotal($supplierId, $periodId, '663', 'credit'),
        ];
    }

    /** Netto zůstatek účtu dle prefixu kódu (vč. analytik) k datu. */
    private function accountBalance(int $supplierId, string $codePrefix, string $asOf): float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(CASE WHEN l.side = \'debit\' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
              WHERE l.supplier_id = ? AND e.supplier_id = ?
                AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                AND ca.account_code LIKE ?'
        );
        $stmt->execute([$supplierId, $supplierId, $asOf, $codePrefix . '%']);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** Σ částek na straně účtu (prefix) ze zápisů fx_revaluation období. */
    private function fxTotal(int $supplierId, int $periodId, string $codePrefix, string $side): float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(l.amount), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
              WHERE l.supplier_id = ? AND e.supplier_id = ?
                AND e.source_type = \'fx_revaluation\' AND e.period_id = ?
                AND e.posted_at IS NOT NULL
                AND l.side = ? AND ca.account_code LIKE ?'
        );
        $stmt->execute([$supplierId, $supplierId, $periodId, $side, $codePrefix . '%']);
        return round((float) $stmt->fetchColumn(), 2);
    }
}
