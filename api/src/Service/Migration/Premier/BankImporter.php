<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;

/**
 * Bankovní účty a pohyby z PREMIER.
 *
 * PREMIER bankovní výpisy jako samostatnou evidenci nevede: výpis je doklad deníku
 * v řadě typu banka (`DOKL_PU.TOK` = 2), jejíž číselník nese analytiku účtu 221
 * (`MD`+`MDA`), číslo účtu (`CISLO_U`, `KOD_U`, `IBAN`) a měnu (`MENA`). Pohyb v MyÚčtu
 * = řádek deníku na účtu řady s nenulovou částkou v měně účtu ({@see PremierJournal::bankAmount()}),
 * výpis = doklad řady (číslo výpisu = `CISLO`). Kurzové přecenění účtu v cizí měně (částka
 * v měně 0) pohyb nezakládá, zůstává jen v deníku.
 *
 * Částka pohybu je v měně účtu: u účtu v cizí měně z řádku deníku (`ZCASTKA`), jinak
 * v Kč. Počáteční zůstatek účtu se dopočte ze všech předchozích let zálohy ve stejné měně,
 * zůstatky výpisů pak navazují po pohybech.
 */
final class BankImporter
{
    public const STEP = 'bank';

    public function __construct(
        private readonly Connection $db,
        private readonly PremierImportRepository $map,
        private readonly SupplierBankAccountRepository $bankAccounts,
    ) {}

    public function import(PremierContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $series = $ctx->journal->bankSeries();
        if ($series === []) {
            $p->finish(self::STEP);
            return;
        }
        $existingStatements = $this->map->all($ctx->supplierId, PremierImportRepository::KIND_BANK_STATEMENT);
        $existingTx = $this->map->all($ctx->supplierId, PremierImportRepository::KIND_BANK_TRANSACTION);
        $insertStatement = $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, source, file_name, file_hash, account_number, bank_code, currency, statement_number,
                 statement_date, transaction_count, imported_by)
             VALUES (?, "import", ?, ?, ?, ?, ?, ?, ?, 0, ?)'
        );
        $insertTx = $pdo->prepare(
            'INSERT INTO bank_transactions
                (source, source_ref, statement_id, posted_at, amount, currency, variable_symbol, constant_symbol,
                 specific_symbol, counterparty_account, counterparty_bank, counterparty_name, description, bank_ref,
                 import_fingerprint)
             VALUES ("statement", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        // Opakovaný převod doplní jen prázdné údaje (starší převod je nepřebíral), nic nepřepíše.
        $enrichTx = $pdo->prepare(
            "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                SET t.variable_symbol = COALESCE(NULLIF(t.variable_symbol, ''), ?),
                    t.constant_symbol = COALESCE(NULLIF(t.constant_symbol, ''), ?),
                    t.specific_symbol = COALESCE(NULLIF(t.specific_symbol, ''), ?),
                    t.counterparty_account = COALESCE(NULLIF(t.counterparty_account, ''), ?),
                    t.counterparty_bank = COALESCE(NULLIF(t.counterparty_bank, ''), ?),
                    t.counterparty_name = COALESCE(NULLIF(t.counterparty_name, ''), ?),
                    t.description = COALESCE(NULLIF(t.description, ''), ?),
                    t.bank_ref = CASE WHEN t.bank_ref IS NULL OR t.bank_ref IN ('', ?) THEN ? ELSE t.bank_ref END
              WHERE t.id = ? AND s.supplier_id = ?"
        );
        $setBalances = $pdo->prepare(
            'UPDATE bank_statements SET transaction_count = ?, prev_balance = ?, curr_balance = ?, credit_total = ?, debit_total = ? WHERE id = ? AND supplier_id = ?'
        );

        foreach ($series as $code => $s) {
            $rows = [];
            foreach ($ctx->journal->year($ctx->year) as $r) {
                if ($r['series'] === $code && $ctx->journal->bankAmount($r) !== null) {
                    $rows[] = $r;
                }
            }
            if ($rows === []) {
                continue;
            }
            if (!$ctx->dryRun && $s['number'] !== '') {
                $this->bankAccounts->registerImported(
                    $ctx->supplierId, $s['number'], $s['bank'] !== '' ? $s['bank'] : null, $s['iban'] !== '' ? $s['iban'] : null,
                    $s['currency'], $s['label'], strlen($s['account']) > 3 ? substr($s['account'], 3) : null,
                );
            }
            $foreign = $s['currency'] !== 'CZK';
            $running = $this->opening($ctx, $s['account'], $foreign);
            $statements = [];
            foreach ($rows as $r) {
                $statements[$r['number']][] = $r;
            }
            uksort($statements, static fn (int|string $a, int|string $b): int => min(array_column($statements[$a], 'date')) <=> min(array_column($statements[$b], 'date')) ?: strnatcmp((string) $a, (string) $b));
            foreach ($statements as $number => $txRows) {
                $mapKey = 'stmt|' . $code . '|' . $ctx->year . '|' . $number;
                $statementId = $existingStatements[$mapKey] ?? null;
                $lastDate = max(array_column($txRows, 'date'));
                if ($statementId === null) {
                    $label = sprintf('%s %s/%d', $code, $number, $ctx->year);
                    $insertStatement->execute([
                        $ctx->supplierId,
                        sprintf('premier-%s.import', preg_replace('/[^A-Za-z0-9_-]/', '_', $label)),
                        hash('sha256', 'premier|' . $ctx->supplierId . '|' . $mapKey),
                        mb_substr($s['number'] !== '' ? $s['number'] : $code, 0, 40),
                        $s['bank'] !== '' ? mb_substr($s['bank'], 0, 4) : null,
                        $s['currency'],
                        mb_substr($label, 0, 20),
                        $lastDate,
                        $ctx->userOrNull(),
                    ]);
                    $statementId = (int) $pdo->lastInsertId();
                    $this->map->put($ctx->supplierId, PremierImportRepository::KIND_BANK_STATEMENT, $mapKey, $statementId, $ctx->runId);
                    $p->count(self::STEP, 'statements');
                }
                $credit = 0.0;
                $debit = 0.0;
                $count = 0;
                foreach ($txRows as $r) {
                    $amount = (float) $ctx->journal->bankAmount($r);
                    $amount >= 0 ? $credit += $amount : $debit -= $amount;
                    $count++;
                    $txKey = 'tx|' . $r['inter'];
                    $d = self::details($r);
                    $fallbackRef = mb_substr($code . '-' . $r['inter'], 0, 40);
                    if (isset($existingTx[$txKey])) {
                        $ctx->bankTransactions[$r['inter']] = $existingTx[$txKey];
                        $p->count(self::STEP, 'existing');
                        $enrichTx->execute([
                            $d['vs'], $d['ks'], $d['ss'], $d['account'], $d['bank'], $d['name'], $d['description'],
                            $fallbackRef, $d['ref'] ?? $fallbackRef, $existingTx[$txKey], $ctx->supplierId,
                        ]);
                        if ($enrichTx->rowCount() > 0) {
                            $p->count(self::STEP, 'enriched');
                        }
                        continue;
                    }
                    $insertTx->execute([
                        mb_substr($code . ' ' . $number . ' #' . $r['inter'], 0, 190),
                        $statementId,
                        $r['date'],
                        number_format($amount, 2, '.', ''),
                        $s['currency'],
                        $d['vs'], $d['ks'], $d['ss'], $d['account'], $d['bank'], $d['name'], $d['description'],
                        $d['ref'] ?? $fallbackRef,
                        hash('sha256', 'premier|' . $ctx->supplierId . '|' . $txKey),
                    ]);
                    $id = (int) $pdo->lastInsertId();
                    $this->map->put($ctx->supplierId, PremierImportRepository::KIND_BANK_TRANSACTION, $txKey, $id, $ctx->runId);
                    $ctx->bankTransactions[$r['inter']] = $id;
                    $p->count(self::STEP, 'transactions');
                }
                $closing = round($running + $credit - $debit, 2);
                $setBalances->execute([
                    $count,
                    number_format($running, 2, '.', ''),
                    number_format($closing, 2, '.', ''),
                    number_format($credit, 2, '.', ''),
                    number_format($debit, 2, '.', ''),
                    $statementId, $ctx->supplierId,
                ]);
                $running = $closing;
            }
        }
        $p->finish(self::STEP);
    }

    /**
     * Údaje pohybu z řádku deníku: VS (`VARIABL`, u příjmu `VAR_DAL`, jinak z homebankingu
     * `HVAR`), protiúčet `HUCET` („předčíslí-číslo/kód banky"), KS `HKS` a SS `HSPEC` (samé
     * nuly = bez symbolu), zpráva pro příjemce `HZPR_PRIJ` do popisu a ID transakce banky
     * `PARTRAN`.
     *
     * @param array<string,mixed> $r
     * @return array{vs:?string,ks:?string,ss:?string,account:?string,bank:?string,name:?string,description:?string,ref:?string}
     */
    public static function details(array $r): array
    {
        $vs = null;
        foreach ([$r['variable_symbol'], $r['variable_symbol_credit'] ?? '', $r['bank_variable_symbol'] ?? ''] as $candidate) {
            $vs = self::symbol((string) $candidate, VariableSymbolNormalizer::MAX_LENGTH);
            if ($vs !== null) {
                break;
            }
        }
        [$account, $bank] = self::counterparty((string) ($r['bank_account'] ?? ''));
        $text = (string) $r['text'];
        $message = (string) ($r['bank_message'] ?? '');
        if ($message !== '' && mb_stripos($text, $message) === false) {
            $text = $text !== '' ? $text . ' | ' . $message : $message;
        }
        $ref = (string) ($r['bank_ref'] ?? '');
        return [
            'vs' => $vs,
            'ks' => self::symbol((string) ($r['bank_constant_symbol'] ?? ''), 10),
            'ss' => self::symbol((string) ($r['bank_specific_symbol'] ?? ''), 20),
            'account' => $account,
            'bank' => $bank,
            'name' => $r['partner_name'] !== '' ? mb_substr((string) $r['partner_name'], 0, 190) : null,
            'description' => $text !== '' ? mb_substr($text, 0, 255) : null,
            'ref' => $ref !== '' ? mb_substr($ref, 0, 40) : null,
        ];
    }

    /** Symbol platby jen číslicemi; prázdný, samé nuly nebo delší než pole = bez symbolu. */
    private static function symbol(string $value, int $maxLength): ?string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        $significant = ltrim($digits, '0');
        if ($significant === '' || strlen($significant) > $maxLength) {
            return null;
        }
        return strlen($digits) <= $maxLength ? $digits : $significant;
    }

    /**
     * Protiúčet z `HUCET`: tuzemský účet „předčíslí-číslo/kód" bez vodicích nul (nulový
     * účet = bez protiúčtu), IBAN beze změny.
     *
     * @return array{0:?string,1:?string}
     */
    private static function counterparty(string $value): array
    {
        $value = strtoupper(str_replace(' ', '', $value));
        if (preg_match('/^(?:(\d{1,6})-)?(\d{1,10})\/(\d{4})$/', $value, $m) === 1) {
            $number = ltrim($m[2], '0');
            if ($number === '') {
                return [null, null];
            }
            $prefix = ltrim($m[1], '0');
            return [($prefix !== '' ? $prefix . '-' : '') . $number, $m[3]];
        }
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $value) === 1) {
            return [$value, null];
        }
        return [null, null];
    }

    /** Zůstatek účtu k začátku roku z deníku předchozích let (v měně účtu). */
    private function opening(PremierContext $ctx, string $account, bool $foreign): float
    {
        if (!$foreign) {
            return round($ctx->opening[$account] ?? 0.0, 2);
        }
        $sum = 0.0;
        $own = $ctx->journal->openingRows($ctx->year);
        if ($own !== []) {
            foreach ($own as $r) {
                $sum += PremierJournal::accountAmount($r, $account, true);
            }
            return round($sum, 2);
        }
        $first = $ctx->journal->firstYear() ?? $ctx->year;
        foreach ($ctx->journal->openingRows($first) as $r) {
            $sum += PremierJournal::accountAmount($r, $account, true);
        }
        for ($y = $first; $y < $ctx->year; $y++) {
            foreach ($ctx->journal->year($y) as $r) {
                $sum += PremierJournal::accountAmount($r, $account, true);
            }
        }
        return round($sum, 2);
    }
}
