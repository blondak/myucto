<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Migration\MoneyS3\AccountCode;

/**
 * Bankovní účty a pohyby z PREMIER.
 *
 * PREMIER bankovní výpisy jako samostatnou evidenci nevede: výpis je doklad deníku
 * v řadě typu banka (`DOKL_PU.TOK` = 2), jejíž číselník nese analytiku účtu 221
 * (`MD`+`MDA`), číslo účtu (`CISLO_U`, `KOD_U`, `IBAN`) a měnu (`MENA`). Pohyb v MyÚčtu
 * = řádek deníku na účtu řady, výpis = doklad řady (číslo výpisu = `CISLO`).
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
        $series = $this->bankSeries($ctx);
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
             VALUES ("statement", ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, ?, ?, ?, ?)'
        );
        $setBalances = $pdo->prepare(
            'UPDATE bank_statements SET transaction_count = ?, prev_balance = ?, curr_balance = ?, credit_total = ?, debit_total = ? WHERE id = ? AND supplier_id = ?'
        );

        foreach ($series as $code => $s) {
            $rows = [];
            foreach ($ctx->journal->year($ctx->year) as $r) {
                if ($r['series'] === $code && ($r['md'] === $s['account'] || $r['dal'] === $s['account'])) {
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
                    $amount = self::amount($r, $s['account'], $foreign);
                    $amount >= 0 ? $credit += $amount : $debit -= $amount;
                    $count++;
                    $txKey = 'tx|' . $r['inter'];
                    if (isset($existingTx[$txKey])) {
                        $ctx->bankTransactions[$r['inter']] = $existingTx[$txKey];
                        $p->count(self::STEP, 'existing');
                        continue;
                    }
                    $vs = preg_replace('/\D/', '', $r['variable_symbol']) ?? '';
                    $insertTx->execute([
                        mb_substr($code . ' ' . $number . ' #' . $r['inter'], 0, 190),
                        $statementId,
                        $r['date'],
                        number_format($amount, 2, '.', ''),
                        $s['currency'],
                        $vs !== '' && ltrim($vs, '0') !== '' && strlen(ltrim($vs, '0')) <= VariableSymbolNormalizer::MAX_LENGTH ? $vs : null,
                        $r['partner_name'] !== '' ? mb_substr($r['partner_name'], 0, 190) : null,
                        $r['text'] !== '' ? mb_substr($r['text'], 0, 255) : null,
                        mb_substr($code . '-' . $r['inter'], 0, 40),
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
     * Bankovní řady deníku z číselníku řad PREMIER.
     *
     * @return array<string,array{account:string,number:string,bank:string,iban:string,currency:string,label:string}>
     */
    private function bankSeries(PremierContext $ctx): array
    {
        $out = [];
        foreach ($ctx->backup->rows('DOKL_PU') as $r) {
            if ((int) ($r['TOK'] ?? 0) !== 2) {
                continue;
            }
            $code = strtoupper(trim((string) ($r['DOKLAD'] ?? '')));
            $account = trim((string) ($r['MD'] ?? '')) . trim((string) ($r['MDA'] ?? ''));
            if ($account === '' || !ctype_digit($account)) {
                $account = trim((string) ($r['DAL'] ?? '')) . trim((string) ($r['DALA'] ?? ''));
            }
            if ($code === '' || !ctype_digit($account) || AccountCode::fromMoney($account) === null) {
                continue;
            }
            $currency = strtoupper(trim((string) ($r['MENA'] ?? '')));
            $out[$code] = [
                'account' => $account,
                'number' => str_replace(' ', '', trim((string) ($r['CISLO_U'] ?? ''))),
                'bank' => trim((string) ($r['KOD_U'] ?? '')),
                'iban' => str_replace(' ', '', trim((string) ($r['IBAN'] ?? ''))),
                // Řada bez měny v číselníku (termínovaný vklad v EUR…) - měna z deníku.
                'currency' => preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : $ctx->journal->accountCurrency($account),
                'label' => trim((string) ($r['NAZEV_B'] ?? '')) ?: (trim((string) ($r['TEXT'] ?? '')) ?: $code),
            ];
        }
        return $out;
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
                $sum += self::amount($r, $account, true);
            }
            return round($sum, 2);
        }
        $first = $ctx->journal->firstYear() ?? $ctx->year;
        foreach ($ctx->journal->openingRows($first) as $r) {
            $sum += self::amount($r, $account, true);
        }
        for ($y = $first; $y < $ctx->year; $y++) {
            foreach ($ctx->journal->year($y) as $r) {
                $sum += self::amount($r, $account, true);
            }
        }
        return round($sum, 2);
    }

    /**
     * Pohyb řádku na účtu v měně účtu (+ příjem, - výdej).
     *
     * @param array<string,mixed> $r
     */
    private static function amount(array $r, string $account, bool $foreign): float
    {
        $movement = PremierJournal::movement($r, $account);
        if (!$foreign || abs($movement) < 0.005) {
            return $movement;
        }
        $value = abs((float) $r['amount_foreign']);
        return round($movement < 0 ? -$value : $value, 2);
    }
}
