<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AssetRepository;

/**
 * Souhrnná karta majetku z účtu, na kterém majetek nemá inventární karty (typicky
 * portfolio pozemků vedené jen v deníku na 031, převzaté z jiného programu).
 *
 * Karta nese celý účet: vstupní cena je počáteční stav účtu (první otevírací zápis),
 * každý další zápis deníku na účtu je zvýšení nebo snížení ceny (technické zhodnocení
 * se znaménkem, datum a doklad zápisu). Karta tak sedí na zůstatek účtu a objeví se
 * v evidenci i inventarizaci majetku. Nic neúčtuje: je v užívání bez zápisu zařazení,
 * neodpisuje se (jen účty neodpisovaného majetku, {@see AssetService::isNonDepreciableAccount()}).
 *
 * Opakované srovnání s deníkem ({@see sync()}) kartu přepočítá z aktuálního deníku.
 * Účet s aktivními vlastními kartami souhrnnou kartu nedostane: pohyby deníku nejde
 * rozdělit mezi karty a karta by majetek započetla podruhé.
 */
final class AccountSummaryCardService
{
    private const INVENTORY_PREFIX = 'UCET-';

    public function __construct(
        private readonly Connection $db,
        private readonly AssetRepository $assets,
        private readonly AssetService $service,
    ) {}

    /**
     * Účty neodpisovaného majetku firmy se zůstatkem nebo se souhrnnou kartou.
     *
     * @return list<array{account_code:string, name:string, balance:float, movements:int,
     *   active_cards:int, summary_asset_id:?int, summary_value:?float, eligible:bool}>
     */
    public function candidates(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT ca.account_code, ca.name,
                    (SELECT COUNT(*) FROM assets a
                      WHERE a.supplier_id = ca.supplier_id AND a.asset_account_code = ca.account_code
                        AND a.status IN ('draft', 'in_use') AND a.summary_account_code IS NULL) AS active_cards,
                    (SELECT a.id FROM assets a
                      WHERE a.supplier_id = ca.supplier_id AND a.summary_account_code = ca.account_code
                      ORDER BY a.id LIMIT 1) AS summary_asset_id
               FROM chart_of_accounts ca
              WHERE ca.supplier_id = ? AND ca.account_code LIKE '0%'
              ORDER BY ca.account_code"
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $code = (string) $r['account_code'];
            if (!AssetService::isNonDepreciableAccount($code)) {
                continue;
            }
            $summaryId = $r['summary_asset_id'] !== null ? (int) $r['summary_asset_id'] : null;
            $ledger = $this->ledger($supplierId, $code, $summaryId);
            if (abs($ledger['end']) < 0.005 && $summaryId === null) {
                continue;
            }
            $summaryValue = null;
            if ($summaryId !== null) {
                $card = $this->service->get($supplierId, $summaryId);
                $summaryValue = $card !== null ? (float) $card['increased_input_price'] : null;
            }
            $out[] = [
                'account_code' => $code,
                'name' => (string) $r['name'],
                'balance' => $ledger['end'],
                'movements' => count($ledger['movements']),
                'active_cards' => (int) $r['active_cards'],
                'summary_asset_id' => $summaryId,
                'summary_value' => $summaryValue,
                'eligible' => (int) $r['active_cards'] === 0,
            ];
        }
        return $out;
    }

    /**
     * Založí souhrnnou kartu účtu, nebo existující srovná s deníkem.
     *
     * @return array{asset: array<string,mixed>, created: bool, improvements: int, warnings: list<array{code:string, message:string}>}
     */
    public function sync(int $supplierId, string $accountCode, ?int $userId = null): array
    {
        $accountCode = trim($accountCode);
        if (!AssetService::isNonDepreciableAccount($accountCode)) {
            throw new AssetException(
                'validation_failed',
                'Souhrnnou kartu lze založit jen k účtu neodpisovaného majetku (031, 032 a jejich analytiky).',
            );
        }
        $account = $this->db->pdo()->prepare('SELECT name FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $account->execute([$supplierId, $accountCode]);
        $accountName = $account->fetchColumn();
        if ($accountName === false) {
            throw new AssetException('not_found', 'Účet ' . $accountCode . ' není v účtové osnově firmy.', 404);
        }
        $active = $this->db->pdo()->prepare(
            "SELECT inventory_number FROM assets
              WHERE supplier_id = ? AND asset_account_code = ? AND status IN ('draft', 'in_use') AND summary_account_code IS NULL"
        );
        $active->execute([$supplierId, $accountCode]);
        $cards = $active->fetchAll(\PDO::FETCH_COLUMN);
        if ($cards !== []) {
            throw new AssetException(
                'account_has_cards',
                'Na účtu ' . $accountCode . ' jsou karty majetku (' . implode(', ', array_slice($cards, 0, 5)) . '): pohyby deníku '
                    . 'nejde rozdělit mezi karty, souhrnná karta by majetek započetla podruhé.',
                409,
            );
        }
        $existing = $this->db->pdo()->prepare('SELECT * FROM assets WHERE supplier_id = ? AND summary_account_code = ? ORDER BY id LIMIT 1');
        $existing->execute([$supplierId, $accountCode]);
        $summary = $existing->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($summary !== null && $summary['status'] !== 'in_use') {
            throw new AssetException('invalid_status', 'Souhrnná karta účtu ' . $accountCode . ' není v užívání, srovnat s deníkem ji nejde.');
        }

        $ledger = $this->ledger($supplierId, $accountCode, $summary !== null ? (int) $summary['id'] : null);
        [$input, $startDate, $moves] = self::split($ledger);
        if ($input === null) {
            throw new AssetException('account_empty', 'Účet ' . $accountCode . ' nemá v deníku kladný stav, souhrnnou kartu není z čeho založit.', 422);
        }
        $warnings = [];
        if ($ledger['gap'] !== null) {
            $warnings[] = [
                'code' => 'opening_gap',
                'message' => 'Počáteční stav účtu ' . $accountCode . ' k ' . $ledger['gap']['date'] . ' nenavazuje na pohyby předchozích let (rozdíl '
                    . number_format($ledger['gap']['amount'], 2, ',', ' ') . ' Kč). Rozdíl nese karta jako samostatný pohyb; ověřte převod počátečních stavů.',
            ];
        }

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            if ($summary === null) {
                $created = $this->service->create($supplierId, [
                    'inventory_number' => $this->inventoryNumber($supplierId, $accountCode),
                    'name' => mb_substr('Souhrnná karta účtu ' . $accountCode . ' - ' . (string) $accountName, 0, 255),
                    'description' => 'Souhrnná karta majetku vedeného jen v deníku: vstupní cena je stav účtu ' . $accountCode
                        . ' k ' . $startDate . ', pohyby deníku na účtu jsou zvýšení a snížení ceny.',
                    'kind' => 'tangible',
                    'asset_account_code' => $accountCode,
                    'accumulated_account_code' => null,
                    'acquisition_account_code' => $this->acquisitionAccount($supplierId, $accountCode),
                    'input_price' => $input,
                    'acquisition_date' => $startDate,
                    'put_into_use_date' => $startDate,
                    'status' => 'in_use',
                    'tax_method' => 'none',
                ], ['user_id' => $userId]);
                $assetId = (int) $created['asset']['id'];
                $this->assets->update($supplierId, $assetId, ['summary_account_code' => $accountCode]);
            } else {
                $assetId = (int) $summary['id'];
                $this->assets->update($supplierId, $assetId, [
                    'input_price' => $input,
                    'acquisition_date' => $startDate,
                    'put_into_use_date' => $startDate,
                ]);
                $pdo->prepare('DELETE FROM asset_improvements WHERE supplier_id = ? AND asset_id = ?')->execute([$supplierId, $assetId]);
            }
            foreach ($moves as $m) {
                $this->assets->insertImprovement($supplierId, $assetId, [
                    'completed_on' => $m['date'],
                    'amount' => $m['amount'],
                    'description' => mb_substr($m['text'], 0, 255),
                ]);
            }
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'asset' => $this->service->get($supplierId, $assetId) ?? [],
            'created' => $summary === null,
            'improvements' => count($moves),
            'warnings' => $warnings,
        ];
    }

    /**
     * Pohyby účtu v deníku: počáteční stav z prvního otevíracího zápisu, pak každý zápis
     * (bez uzávěrkových a dalších otevíracích) jako pohyb. Nesedí-li pozdější otevírací
     * stav na součet pohybů, rozdíl je samostatný pohyb k jeho datu (`gap`), aby karta
     * seděla na poslední stav účtu. Zápisy zařazení a vyřazení jiných karet se nepočítají.
     *
     * @return array{start:float, start_date:?string, movements:list<array{date:string, amount:float, text:string}>,
     *   end:float, gap:?array{date:string, amount:float}}
     */
    private function ledger(int $supplierId, string $accountCode, ?int $summaryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT je.id, je.entry_date, je.source_type, je.document_no, je.description,
                    SUM(CASE WHEN jl.side = 'debit' THEN jl.amount ELSE -jl.amount END) AS net
               FROM journal_entries je
               JOIN journal_entry_lines jl ON jl.entry_id = je.id AND jl.supplier_id = je.supplier_id
               JOIN chart_of_accounts ca ON ca.id = jl.account_id
              WHERE je.supplier_id = ? AND ca.account_code = ? AND je.posted_at IS NOT NULL
                AND je.source_type <> 'closing'
                AND NOT (je.source_type IN ('asset', 'asset_disposal') AND (je.source_id IS NULL OR je.source_id <> ?))
              GROUP BY je.id, je.entry_date, je.source_type, je.document_no, je.description
              ORDER BY je.entry_date, je.id"
        );
        $stmt->execute([$supplierId, $accountCode, $summaryId ?? 0]);
        $start = 0.0;
        $startDate = null;
        $movements = [];
        $running = 0.0;
        $gap = null;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $net = round((float) $r['net'], 2);
            $date = (string) $r['entry_date'];
            if ((string) $r['source_type'] === 'opening') {
                if ($startDate === null && $movements === []) {
                    $start = round($start + $net, 2);
                    $startDate = $date;
                    $running = $start;
                    continue;
                }
                // Otevírací stav dalšího roku: jen kontrola návaznosti.
                $diff = round($net - $running, 2);
                if (abs($diff) >= 0.01) {
                    $movements[] = ['date' => $date, 'amount' => $diff, 'text' => 'Rozdíl počátečního stavu k ' . $date . ' proti pohybům deníku'];
                    $running = $net;
                    $gap ??= ['date' => $date, 'amount' => $diff];
                }
                continue;
            }
            if (abs($net) < 0.005) {
                continue;
            }
            $startDate ??= $date;
            $running = round($running + $net, 2);
            $text = trim(($r['document_no'] !== null && $r['document_no'] !== '' ? (string) $r['document_no'] . ' ' : '') . (string) ($r['description'] ?? ''));
            $movements[] = ['date' => $date, 'amount' => $net, 'text' => 'Deník: ' . ($text !== '' ? $text : 'zápis č. ' . (int) $r['id'])];
        }
        return ['start' => $start, 'start_date' => $startDate, 'movements' => $movements, 'end' => $running, 'gap' => $gap];
    }

    /**
     * Vstupní cena a datum karty a zbylé pohyby: počáteční stav, a je-li nulový, první
     * kladný kumulovaný stav z pohybů.
     *
     * @param array{start:float, start_date:?string, movements:list<array{date:string, amount:float, text:string}>} $ledger
     * @return array{0:?float, 1:?string, 2:list<array{date:string, amount:float, text:string}>}
     */
    private static function split(array $ledger): array
    {
        $value = $ledger['start'];
        if ($value > 0.005) {
            return [round($value, 2), $ledger['start_date'], $ledger['movements']];
        }
        foreach ($ledger['movements'] as $i => $m) {
            $value = round($value + $m['amount'], 2);
            if ($value > 0.005) {
                return [$value, $m['date'], array_slice($ledger['movements'], $i + 1)];
            }
        }
        return [null, null, []];
    }

    private function inventoryNumber(int $supplierId, string $accountCode): string
    {
        $base = mb_substr(self::INVENTORY_PREFIX . $accountCode, 0, 30);
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM assets WHERE supplier_id = ? AND inventory_number = ?');
        for ($i = 1; $i < 100; $i++) {
            $number = $i === 1 ? $base : mb_substr($base, 0, 26) . '-' . $i;
            $stmt->execute([$supplierId, $number]);
            if ($stmt->fetchColumn() === false) {
                return $number;
            }
        }
        throw new AssetException('duplicate_inventory_number', 'Pro souhrnnou kartu se nepodařilo najít volné inventární číslo.');
    }

    /** Účet pořízení 042 (první analytika osnovy); karta se neúčtuje, bez něj majetkový účet. */
    private function acquisitionAccount(int $supplierId, string $accountCode): string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT account_code FROM chart_of_accounts
              WHERE supplier_id = ? AND (account_code = '042' OR account_code LIKE '042.%')
              ORDER BY account_code = '042' DESC, account_code LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $code = $stmt->fetchColumn();
        return $code !== false ? (string) $code : $accountCode;
    }
}
