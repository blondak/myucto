<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Codebooks;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\PostingRuleRepository;

/**
 * Import kontačních pravidel z XLSX/CSV (Epic F5 §4.4, R11). Identita = rule_key →
 * firemní override s fixní priority = OVERRIDE_PRIORITY (100). Sémantika 1:1
 * s PostingRuleRepository::upsertOverride. `priorita`/`zdroj` jsou export-only,
 * na importu se ignorují. Neznámý klíč (mimo globální seed) = error; nové typy
 * kontací nelze zakládat. Skip, když efektivní hodnoty odpovídají řádku (re-import
 * exportu s globálními řádky NEvytváří overridy).
 */
final class PostingRulesImportService extends AbstractCodebookImportService
{
    public function __construct(
        private readonly PostingRuleRepository $rules,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly Connection $db,
    ) {}

    public static function columns(): array
    {
        return [
            'key'         => ['header' => 'klic', 'aliases' => ['rule_key', 'klíč', 'key'],
                              'required' => 'ano', 'note' => '^[A-Za-z0-9._-]{1,64}$; musí existovat v globálním seedu'],
            'description' => ['header' => 'popis', 'aliases' => ['description'],
                              'required' => 'ne', 'note' => 'max 255; prázdné = převezme se z efektivního pravidla'],
            'debit'       => ['header' => 'md_ucet', 'aliases' => ['debit_account_code', 'debit', 'md'],
                              'required' => 'ne', 'note' => 'kód aktivního účtu osnovy, nebo prázdné = NULL'],
            'credit'      => ['header' => 'd_ucet', 'aliases' => ['credit_account_code', 'credit', 'dal', 'd'],
                              'required' => 'ne', 'note' => 'kód aktivního účtu osnovy, nebo prázdné = NULL'],
            'active'      => ['header' => 'aktivni', 'aliases' => ['is_active'],
                              'required' => 'ne (default 1)', 'note' => 'informativní'],
            'priority'    => ['header' => 'priorita', 'aliases' => ['priority'],
                              'required' => 'export-only', 'note' => '0 = globál, 100 = override; na importu IGNOROVÁNA (R11)'],
            'source'      => ['header' => 'zdroj', 'aliases' => ['source'],
                              'required' => 'export-only', 'note' => 'globální/firemní; na importu ignorován'],
        ];
    }

    protected function requiredHeaderKeys(): array
    {
        return ['key'];
    }

    protected function process(int $supplierId, array $map, array $rows, bool $dryRun): array
    {
        $pdo = $this->db->pdo();

        $effective = $this->rules->effectiveMap($supplierId);

        $activeCodes = [];
        foreach ($this->accounts->listForTenant($supplierId, false) as $acc) {
            $activeCodes[(string) $acc['account_code']] = true;
        }

        $overrideKeys = [];
        $ov = $pdo->prepare('SELECT rule_key FROM posting_rules WHERE supplier_id = ? AND priority = ?');
        $ov->execute([$supplierId, PostingRuleRepository::OVERRIDE_PRIORITY]);
        foreach ($ov->fetchAll(\PDO::FETCH_COLUMN) as $k) {
            $overrideKeys[(string) $k] = true;
        }

        $reportRows = [];
        $writers = [];
        $seen = [];

        ksort($rows);
        foreach ($rows as $line => $cols) {
            $key = $this->col($cols, $map, 'key');
            $row = ['line' => $line, 'key' => $key, 'status' => 'skip'];

            if ($key === '') {
                $reportRows[$line] = $this->err($row, 'Chybí klíč kontace.');
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $key)) {
                $reportRows[$line] = $this->err($row, 'Neplatný formát klíče „' . $key . '".');
                continue;
            }
            if (isset($seen[$key])) {
                $reportRows[$line] = $this->err($row, 'Klíč „' . $key . '" je v souboru vícekrát.');
                continue;
            }
            $seen[$key] = true;

            if (!isset($effective[$key])) {
                $reportRows[$line] = $this->err($row, 'Neznámý klíč kontace „' . $key . '" — nové typy kontací nelze zakládat.');
                continue;
            }

            $md = $this->col($cols, $map, 'debit');
            $dcr = $this->col($cols, $map, 'credit');
            $desc = $this->col($cols, $map, 'description');

            $desiredDebit = $md === '' ? null : $md;
            $desiredCredit = $dcr === '' ? null : $dcr;

            $eff = $effective[$key];
            $effDebit = $eff['debit_account_code'] !== null ? (string) $eff['debit_account_code'] : null;
            $effCredit = $eff['credit_account_code'] !== null ? (string) $eff['credit_account_code'] : null;
            $effDesc = (string) ($eff['description'] ?? $key);
            $desiredDesc = $desc !== '' ? $desc : $effDesc;

            // Round-trip skip PŘED validací účtů — export globálního řádku (jehož účet
            // třeba není v osnově firmy) se nesmí měnit ani validovat, jen potvrdit.
            if ($effDebit === $desiredDebit && $effCredit === $desiredCredit && $effDesc === $desiredDesc) {
                $row['status'] = 'skip';
                $reportRows[$line] = $row;
                continue;
            }

            if ($desiredDebit === null && $desiredCredit === null) {
                $reportRows[$line] = $this->err($row, 'Zadej alespoň MD nebo D účet.');
                continue;
            }
            foreach (['MD' => $desiredDebit, 'D' => $desiredCredit] as $label => $code) {
                if ($code !== null && !isset($activeCodes[$code])) {
                    $reportRows[$line] = $this->err($row, 'Účet ' . $code . ' (' . $label . ') není v aktivní osnově firmy.');
                    continue 2;
                }
            }

            $changes = [];
            if ($effDebit !== $desiredDebit) {
                $changes['debit_account_code'] = ['from' => $effDebit, 'to' => $desiredDebit];
            }
            if ($effCredit !== $desiredCredit) {
                $changes['credit_account_code'] = ['from' => $effCredit, 'to' => $desiredCredit];
            }
            if ($effDesc !== $desiredDesc) {
                $changes['description'] = ['from' => $effDesc, 'to' => $desiredDesc];
            }

            $row['status'] = isset($overrideKeys[$key]) ? 'update' : 'create';
            $row['changes'] = $changes;
            $writers[] = function () use ($supplierId, $key, $desiredDebit, $desiredCredit, $desiredDesc): void {
                $this->rules->upsertOverride($supplierId, $key, $desiredDebit, $desiredCredit, $desiredDesc);
            };
            $reportRows[$line] = $row;
        }

        return $this->summarize($dryRun, $reportRows, $writers, $pdo);
    }

    /** @param array<string,mixed> $row */
    private function err(array $row, string $message): array
    {
        $row['status'] = 'error';
        $row['message'] = $message;
        unset($row['changes']);
        return $row;
    }
}
