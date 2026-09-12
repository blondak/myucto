<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Bank\StatementBalanceService;
use PDO;

/**
 * Pokladny a pokladní doklady (`SzUcPokl` typ P, `PoklKnih`), bankovní účty a pohyby
 * (`SzUcPokl` typ U, `BankKnih`).
 *
 * Money výpis jako soubor nemá, jen jednotlivé bankovní doklady. Na účet a rok proto
 * vznikne jeden souhrnný výpis se zdrojem `import` (migrace 1771). Žádná jiná hodnota
 * není pravdivá: `gpc` slibuje nahraný soubor, `pdf` rozparsovaný výpis a `bank_api`
 * má vedlejší účinek — podle něj se hledají účty s aktivním napojením na banku. Cesta
 * „složit GPC a pustit ho přes importér výpisů" je záměrně zavřená: importér
 * rekonstruovaný výpis odmítá, protože výpis v systému má být bankou potvrzený originál.
 */
final class CashBankImporter
{
    public const STEP_CASH = 'cash';
    public const STEP_BANK = 'bank';

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
    ) {}

    public function importCash(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $registers = [];
        foreach ($ctx->backup->rowsAcrossYears('SzUcPokl') as $r) {
            $code = trim((string) ($r['Zkrat'] ?? ''));
            if ($code === '' || strtoupper(trim((string) ($r['UcPokl'] ?? ''))) !== 'P' || isset($registers[$code])) {
                continue;
            }
            $registers[$code] = $this->ensureRegister($ctx, $code, trim((string) ($r['Popis'] ?? '')) ?: $code, (string) ($r['PrimUcet'] ?? ''));
        }

        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_CASH_DOCUMENT);
        $ruleExists = $pdo->prepare('SELECT 1 FROM posting_rules WHERE supplier_id = ? AND rule_key = ? LIMIT 1');
        $numberTaken = $pdo->prepare('SELECT 1 FROM cash_documents WHERE supplier_id = ? AND doc_number = ? LIMIT 1');
        $insert = $pdo->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, tax_date,
                 partner_name, partner_ic, partner_dic, description, vat_mode, total_amount, currency_code,
                 rule_key, external_barcode, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "CZK", ?, ?, "posted", ?)'
        );
        $insertVat = $pdo->prepare(
            'INSERT INTO cash_document_vat_lines (cash_document_id, vat_rate, base_amount, vat_amount, vat_deduction) VALUES (?, ?, ?, ?, ?)'
        );
        // Každá pokladna má vlastní číselnou řadu — stejné číslo v další pokladně téhož
        // roku dostane klíč s kódem pokladny (první si ponechá „rok|číslo").
        $owner = [];
        foreach ($ctx->backup->rowsAcrossYears('PoklKnih') as $r) {
            $year = $ctx->yearOf($r);
            $docNo = trim((string) ($r['Doklad'] ?? ''));
            if ($year === null || $docNo === '') {
                continue;
            }
            $registerCode = trim((string) ($r['Pokl'] ?? ''));
            $plain = $year . '|' . $docNo;
            $owner[$plain] ??= $registerCode;
            $key = $owner[$plain] === $registerCode ? $plain : $plain . '|' . $registerCode;
            if (isset($existing[$key])) {
                $ctx->cashDocuments[$key] = $existing[$key];
                $p->count(self::STEP_CASH, 'existing');
                continue;
            }
            if ($ctx->isLocked($year)) {
                $p->warn(self::STEP_CASH, 'year_locked', "Pokladní doklad {$docNo}: rok {$year} je uzavřený, doklad nepřevzat.");
                continue;
            }
            $registerId = $registers[trim((string) ($r['Pokl'] ?? ''))] ?? null;
            $issue = InvoiceImporter::date($r, ['DatVyst', 'DatUcPr']);
            if ($registerId === null || $issue === null) {
                $p->warn(self::STEP_CASH, 'register_or_date_missing', "Pokladní doklad {$docNo} nemá pokladnu nebo datum, nepřevzat.");
                continue;
            }
            $number = mb_substr($docNo, 0, 30);
            $numberTaken->execute([$ctx->supplierId, $number]);
            if ($numberTaken->fetchColumn() !== false) {
                $number = mb_substr($docNo . '/' . $year, 0, 30);
            }
            $amount = round((float) ($r['Celkem'] ?? 0), 2);
            if ($amount === 0.0) {
                // Nulový doklad nemá v pokladně účinek a MyÚčto ho nepřijme (částka > 0).
                $p->count(self::STEP_CASH, 'zero_amount');
                continue;
            }
            // Záporný příjem je výdej a naopak (vratka v pokladně) — jen tak sedí 211 na deník.
            $isOut = (((int) ($r['Vydej'] ?? 0)) === 1) !== ($amount < 0);
            $vatLines = self::vatLines($r, $amount < 0);
            $cleneni = trim((string) ($r['Cleneni'] ?? ''));
            // Pokladní doklad je tuzemské plnění (řádky 1/2, 40/41) — stejné členění jako
            // u faktur ({@see Ms3VatCode}), krácený odpočet § 76 včetně.
            $vatClass = Ms3VatCode::resolve($cleneni, !$isOut);
            if ($vatLines !== [] && $isOut && $vatClass !== null && !$vatClass['in_return']) {
                // Výdej mimo přiznání: daň je součástí nákladu, odpočet se neuplatnil.
                $vatLines = [];
            } elseif ($vatLines !== [] && ($vatClass === null || !$vatClass['in_return'] || $vatClass['code'] !== null)) {
                // Mimo tuzemské plnění (PDP, EU, bez nároku) převod DPH neodhaduje — doklad
                // zůstane bez DPH a účetní ho doplní; v deníku z Money DPH je.
                $p->warn(self::STEP_CASH, 'cash_vat_review', "Pokladní doklad {$docNo} ({$year}): členění DPH „{$cleneni}“ převod nepřebírá, DPH doplňte ručně.", ['document_no' => $docNo, 'year' => $year]);
                $vatLines = [];
            }
            $vatDeduction = $vatClass['deduction'] ?? 'full';
            $rule = mb_substr(trim((string) ($r['PrKont'] ?? '')), 0, 64);
            $ruleKey = null;
            if ($rule !== '') {
                $ruleExists->execute([$ctx->supplierId, $rule]);
                $ruleKey = $ruleExists->fetchColumn() !== false ? $rule : null;
            }
            $insert->execute([
                $ctx->supplierId,
                $registerId,
                $isOut ? 'out' : 'in',
                $isOut ? 'purchase' : 'sale',
                $number,
                $issue,
                InvoiceImporter::date($r, ['DatUplDPH']) ?? $issue,
                mb_substr(trim((string) ($r['AdNazev'] ?? '')), 0, 255) ?: null,
                mb_substr(CodebookImporter::ico((string) ($r['AdICO'] ?? '')), 0, 20) ?: null,
                mb_substr(strtoupper(str_replace(' ', '', trim((string) ($r['AdDIC'] ?? '')))), 0, 20) ?: null,
                mb_substr(trim((string) ($r['Popis'] ?? '')) ?: $docNo, 0, 255),
                $vatLines === [] ? 'none' : 'vat',
                abs($amount),
                $ruleKey,
                mb_substr(trim((string) ($r['BarCode'] ?? '')), 0, 64) ?: null,
                $ctx->userId > 0 ? $ctx->userId : null,
            ]);
            $id = (int) $pdo->lastInsertId();
            foreach ($vatLines as $line) {
                $insertVat->execute([$id, $line['rate'], $line['base'], $line['vat'], $vatDeduction]);
            }
            if ($vatLines !== []) {
                $p->count(self::STEP_CASH, 'with_vat');
            }
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_CASH_DOCUMENT, $key, $id, $ctx->runId);
            $ctx->cashDocuments[$key] = $id;
            $p->count(self::STEP_CASH, 'created');
        }
        $p->finish(self::STEP_CASH);
    }

    public function importBank(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $accounts = [];
        foreach ($ctx->backup->rowsAcrossYears('SzUcPokl') as $r) {
            $code = trim((string) ($r['Zkrat'] ?? ''));
            if ($code !== '' && strtoupper(trim((string) ($r['UcPokl'] ?? ''))) === 'U') {
                // Pozdější rok přepíše dřívější — firma mohla banku mezitím změnit.
                $accounts[$code] = [
                    'number' => trim((string) ($r['Ucet'] ?? '')),
                    'bank' => trim((string) ($r['BKod'] ?? '')),
                    'iban' => trim((string) ($r['IBAN'] ?? '')),
                    'primary' => trim((string) ($r['PrimUcet'] ?? '')),
                    'currency' => self::currency((string) ($r['Mena'] ?? '')),
                    'year' => (int) ($ctx->yearOf($r) ?? 0),
                ];
            }
        }
        // Zkouška nanečisto účet firmy nedoplňuje: zámek řádku měny by v její transakci
        // blokoval vystavování dokladů firmy (cizí klíč na měnu) až do konce zkoušky.
        if (!$ctx->options->isDryRun()) {
            $this->fillOwnAccount($ctx->supplierId, $accounts);
        }

        $byStatement = [];
        foreach ($ctx->backup->rowsAcrossYears('BankKnih') as $r) {
            $year = $ctx->yearOf($r);
            if ($year === null || trim((string) ($r['Doklad'] ?? '')) === '') {
                continue;
            }
            $code = trim((string) ($r['Ucet'] ?? '')) ?: ((string) (array_key_first($accounts) ?? 'BANKA'));
            $byStatement[$year . '|' . $code][] = $r;
        }

        ksort($byStatement);
        $txKeys = self::documentKeys($byStatement);

        $existingStatements = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_BANK_STATEMENT);
        $existingTx = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_BANK_TRANSACTION);
        // Výpis převzatý z Money je výpis se stavem účtu (zdroj `import`): záložka Stavy na
        // účtech ho bere jako kotvu zůstatku a jde z něj vytvořit GPC. Money čísluje výpisy
        // u každého účtu v roce (`Vypis`) — převod je založí stejně.
        $insertStatement = $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, source, file_name, file_hash, account_number, bank_code,
                 currency, statement_number, statement_date, transaction_count, imported_by)
             VALUES (?, "import", ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertTx = $pdo->prepare(
            'INSERT INTO bank_transactions
                (source, source_ref, statement_id, posted_at, amount, currency, variable_symbol,
                 counterparty_name, description, bank_ref, import_fingerprint)
             VALUES ("statement", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $touchStatement = $pdo->prepare(
            'UPDATE bank_statements SET transaction_count = transaction_count + ?, statement_date = GREATEST(statement_date, ?)
              WHERE id = ? AND supplier_id = ?'
        );
        // Stav korunového účtu na začátku roku = otevírací zápis účtu banky v deníku; dál
        // se počítá po pohybech výpisů. Účet v cizí měně deník v té měně nevede — počáteční
        // stav je součet pohybů účtu ze všech předchozích let zálohy ({@see foreignOpenings()}).
        $ledgerOpening = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND e.period_id = ? AND l.account_id = ? AND e.source_type = 'opening'"
        );
        $setBalances = $pdo->prepare(
            'UPDATE bank_statements SET prev_balance = ?, curr_balance = ?, credit_total = ?, debit_total = ? WHERE id = ? AND supplier_id = ?'
        );
        $foreignOpenings = self::foreignOpenings($ctx, $accounts);

        foreach ($byStatement as $statementKey => $rows) {
            [$yearText, $code] = explode('|', $statementKey, 2);
            $year = (int) $yearText;
            if ($ctx->isLocked($year)) {
                $p->info(self::STEP_BANK, 'year_locked', "Bankovní pohyby účtu {$code} za rok {$year}: rok je uzavřený, nepřebírají se.");
                foreach (array_keys($rows) as $i) {
                    $txKey = $txKeys[$statementKey][$i];
                    if (isset($existingTx[$txKey])) {
                        $ctx->bankTransactions[$txKey] = $existingTx[$txKey];
                    }
                }
                continue;
            }
            $meta = $accounts[$code] ?? ['number' => '', 'bank' => '', 'iban' => '', 'primary' => '', 'currency' => 'CZK', 'year' => 0];
            $currency = $meta['currency'];
            $running = null;
            if ($currency === 'CZK') {
                $primary = AccountCode::fromMoney($meta['primary']);
                $accountId = $primary !== null ? ($ctx->accountIds[$primary] ?? null) : null;
                $periodId = $ctx->periods[$year]['id'] ?? null;
                if ($accountId !== null && $periodId !== null) {
                    $ledgerOpening->execute([$ctx->supplierId, $periodId, $accountId]);
                    $running = StatementBalanceService::cents(number_format((float) $ledgerOpening->fetchColumn(), 2, '.', ''));
                }
            } elseif (isset($foreignOpenings[$code][$year])) {
                $running = $foreignOpenings[$code][$year];
            }

            $groups = [];
            foreach ($rows as $i => $r) {
                $groups[(int) ($r['Vypis'] ?? 0)][] = $i;
            }
            ksort($groups);
            // Výpis z dřívějšího převodu (jeden za rok) zůstává — pohyby už jsou v něm.
            $legacyStatementId = $existingStatements[$statementKey] ?? null;
            foreach ($groups as $vypis => $indexes) {
                $dates = [];
                foreach ($indexes as $i) {
                    $d = InvoiceImporter::date($rows[$i], ['DatPlat', 'DatUcPr']);
                    if ($d !== null) {
                        $dates[] = $d;
                    }
                }
                sort($dates);
                $lastDate = $dates === [] ? sprintf('%04d-12-31', $year) : $dates[count($dates) - 1];

                $mapKey = $statementKey . '|' . $vypis;
                $statementId = $legacyStatementId ?? ($existingStatements[$mapKey] ?? null);
                if ($statementId === null) {
                    $label = $vypis > 0 ? sprintf('%s/%d/%d', $code, $year, $vypis) : sprintf('%s/%d', $code, $year);
                    $insertStatement->execute([
                        $ctx->supplierId,
                        sprintf('money-s3-%s.import', preg_replace('/[^A-Za-z0-9_-]/', '_', $label)),
                        hash('sha256', 'money-s3|' . $ctx->supplierId . '|' . $mapKey),
                        mb_substr($meta['number'] !== '' ? $meta['number'] : $code, 0, 40),
                        $meta['bank'] !== '' ? mb_substr($meta['bank'], 0, 4) : null,
                        $currency,
                        mb_substr($label, 0, 20),
                        $lastDate,
                        0,
                        $ctx->userId > 0 ? $ctx->userId : null,
                    ]);
                    $statementId = (int) $pdo->lastInsertId();
                    $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_BANK_STATEMENT, $mapKey, $statementId, $ctx->runId);
                    $p->count(self::STEP_BANK, 'statements');
                }

                $added = 0;
                $credit = 0;
                $debit = 0;
                foreach ($indexes as $i) {
                    $r = $rows[$i];
                    $docNo = trim((string) $r['Doklad']);
                    $txKey = $txKeys[$statementKey][$i];
                    [$amount, $czk] = self::transactionAmount($r, $currency);
                    $cents = StatementBalanceService::cents(number_format($amount, 2, '.', ''));
                    if ($cents >= 0) {
                        $credit += $cents;
                    } else {
                        $debit -= $cents;
                    }
                    if (isset($existingTx[$txKey])) {
                        $ctx->bankTransactions[$txKey] = $existingTx[$txKey];
                        if ($currency !== 'CZK') {
                            $ctx->bankTransactionCzk[$existingTx[$txKey]] = $czk;
                        }
                        $p->count(self::STEP_BANK, 'existing');
                        continue;
                    }
                    $insertTx->execute([
                        mb_substr($docNo, 0, 190),
                        $statementId,
                        InvoiceImporter::date($r, ['DatPlat', 'DatUcPr']) ?? $lastDate,
                        number_format($amount, 2, '.', ''),
                        $currency,
                        mb_substr(trim((string) ($r['VarSym'] ?? '')), 0, 20) ?: null,
                        mb_substr(trim((string) ($r['AdNazev'] ?? '')), 0, 190) ?: null,
                        mb_substr(trim((string) ($r['Popis'] ?? '')), 0, 255) ?: null,
                        mb_substr($docNo, 0, 40),
                        hash('sha256', 'money-s3|' . $ctx->supplierId . '|' . $txKey),
                    ]);
                    $id = (int) $pdo->lastInsertId();
                    $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_BANK_TRANSACTION, $txKey, $id, $ctx->runId);
                    $ctx->bankTransactions[$txKey] = $id;
                    if ($currency !== 'CZK') {
                        $ctx->bankTransactionCzk[$id] = $czk;
                    }
                    $added++;
                    $p->count(self::STEP_BANK, 'transactions');
                }
                if ($added > 0) {
                    $touchStatement->execute([$added, $lastDate, $statementId, $ctx->supplierId]);
                }
                if ($running !== null && $legacyStatementId === null) {
                    $closing = $running + $credit - $debit;
                    $setBalances->execute([
                        number_format($running / 100, 2, '.', ''),
                        number_format($closing / 100, 2, '.', ''),
                        number_format($credit / 100, 2, '.', ''),
                        number_format($debit / 100, 2, '.', ''),
                        $statementId, $ctx->supplierId,
                    ]);
                    $running = $closing;
                    $p->count(self::STEP_BANK, 'balances');
                }
            }
        }
        $p->finish(self::STEP_BANK);
    }

    /**
     * Částka pohybu v měně účtu a v Kč. Výdej = odchozí platba (minus). Pohyb účtu v cizí
     * měně nese Money ve valutách (`ValutyKUhr`), `Celkem` je přepočet v Kč.
     *
     * @param array<string,mixed> $r
     * @return array{0:float,1:float} [částka v měně účtu, částka v Kč]
     */
    private static function transactionAmount(array $r, string $currency): array
    {
        $sign = ((int) ($r['Vydej'] ?? 0)) === 1 ? -1 : 1;
        $czk = round(abs((float) ($r['Celkem'] ?? 0)), 2);
        if ($currency === 'CZK') {
            return [$sign * $czk, $sign * $czk];
        }
        $foreign = abs((float) ($r['ValutyKUhr'] ?? 0)) ?: abs((float) ($r['ValutyZak0'] ?? 0));
        if ($foreign === 0.0 && (float) ($r['Kurs'] ?? 0) > 0) {
            $foreign = $czk / (float) $r['Kurs'] * max(1.0, (float) ($r['PocetJedn'] ?? 1));
        }
        return [$sign * round($foreign, 2), $sign * $czk];
    }

    /**
     * Počáteční stav účtů v cizí měně po letech (v haléřích té měny): součet pohybů účtu
     * ze všech dřívějších let zálohy, včetně let, které se nepřevádějí.
     *
     * @param array<string,array{currency:string}> $accounts
     * @return array<string,array<int,int>> kód účtu => rok => počáteční stav
     */
    private static function foreignOpenings(ImportContext $ctx, array $accounts): array
    {
        $byYear = [];
        foreach ($ctx->backup->rowsAcrossYears('BankKnih') as $r) {
            $code = trim((string) ($r['Ucet'] ?? ''));
            // Roky před převáděným obdobím v mapě adresářů nejsou — rok dá datum pohybu.
            $date = InvoiceImporter::date($r, ['DatUcPr', 'DatPlat']);
            $year = $ctx->dirYears[$r['__dir']] ?? ($date !== null ? (int) substr($date, 0, 4) : null);
            $currency = $accounts[$code]['currency'] ?? 'CZK';
            if ($currency === 'CZK' || $year === null || trim((string) ($r['Doklad'] ?? '')) === '') {
                continue;
            }
            [$amount] = self::transactionAmount($r, $currency);
            $byYear[$code][$year] = ($byYear[$code][$year] ?? 0) + StatementBalanceService::cents(number_format($amount, 2, '.', ''));
        }
        $out = [];
        foreach ($byYear as $code => $years) {
            ksort($years);
            $running = 0;
            foreach ($years as $year => $sum) {
                $out[$code][$year] = $running;
                $running += $sum;
            }
        }
        return $out;
    }

    /**
     * DPH pokladního dokladu po sazbách: snížená (`ZaklSS`/`DPHSS`, sazba `SSazba`),
     * základní (`ZaklZS`/`DPHZS`, `ZSazba`) a další sazby `Zaklad_3…6`/`DPH_3…6`
     * (`SazbaDPH_3…6`). Jen řádky s daní — nulová sazba do evidence DPH nejde. Záporný
     * doklad se převádí s opačným směrem, proto se znaménko částek obrací.
     *
     * @param array<string,mixed> $r
     * @return list<array{rate:float,base:float,vat:float}>
     */
    public static function vatLines(array $r, bool $negative): array
    {
        $slots = [['ZaklSS', 'DPHSS', 'SSazba'], ['ZaklZS', 'DPHZS', 'ZSazba']];
        for ($i = 3; $i <= 6; $i++) {
            $slots[] = ['Zaklad_' . $i, 'DPH_' . $i, 'SazbaDPH_' . $i];
        }
        $sign = $negative ? -1 : 1;
        $out = [];
        foreach ($slots as [$baseField, $vatField, $rateField]) {
            $vat = round((float) ($r[$vatField] ?? 0), 2);
            $rate = (float) ($r[$rateField] ?? 0);
            if ($vat === 0.0 || $rate <= 0.0) {
                continue;
            }
            $out[] = ['rate' => $rate, 'base' => round($sign * (float) ($r[$baseField] ?? 0), 2), 'vat' => round($sign * $vat, 2)];
        }
        return $out;
    }

    /**
     * Klíč bankovního dokladu v mapě převodu. Každý účet má v Money vlastní číselnou řadu,
     * takže stejné číslo dokladu na dalším účtu téhož roku je běžné: první účet (podle
     * kódu) si ponechá klíč „rok|číslo" — mapy dřívějších převodů platí dál — další účet
     * dostane „rok|číslo|kód účtu".
     *
     * @param array<string,list<array<string,mixed>>> $byStatement "rok|kód účtu" => řádky
     * @return array<string,array<int,string>>
     */
    private static function documentKeys(array $byStatement): array
    {
        $owner = [];
        $used = [];
        $keys = [];
        foreach ($byStatement as $statementKey => $rows) {
            [$year, $code] = explode('|', $statementKey, 2);
            foreach ($rows as $i => $r) {
                $plain = $year . '|' . trim((string) $r['Doklad']);
                $owner[$plain] ??= $code;
                $key = $owner[$plain] === $code ? $plain : $plain . '|' . $code;
                $used[$key] = ($used[$key] ?? 0) + 1;
                $keys[$statementKey][$i] = $used[$key] === 1 ? $key : $key . '#' . $used[$key];
            }
        }
        return $keys;
    }

    /**
     * Pokladna na stejném účtu, kterou firma už má, se použije — pokladna je v MyÚčtu
     * vázaná na účet (unikátní na firmu), druhá na týž účet nevznikne.
     */
    private function ensureRegister(ImportContext $ctx, string $code, string $name, string $primaryAccount): int
    {
        $mapped = $this->map->get($ctx->supplierId, MoneyS3ImportRepository::KIND_CASH_REGISTER, $code);
        if ($mapped !== null) {
            return $mapped;
        }
        $pdo = $this->db->pdo();
        $account = AccountCode::fromMoney($primaryAccount);
        $account = $account !== null && isset($ctx->accountIds[$account]) ? $account : null;
        if ($account !== null) {
            $stmt = $pdo->prepare('SELECT id FROM cash_registers WHERE supplier_id = ? AND account_code = ? LIMIT 1');
            $stmt->execute([$ctx->supplierId, $account]);
            $found = $stmt->fetchColumn();
            if ($found !== false) {
                $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_CASH_REGISTER, $code, (int) $found, $ctx->runId);
                return (int) $found;
            }
        }
        $nameTaken = $pdo->prepare('SELECT 1 FROM cash_registers WHERE supplier_id = ? AND name = ? LIMIT 1');
        $nameTaken->execute([$ctx->supplierId, mb_substr($name, 0, 100)]);
        if ($nameTaken->fetchColumn() !== false) {
            $name .= ' (Money S3 ' . $code . ')';
        }
        $pdo->prepare(
            'INSERT INTO cash_registers (supplier_id, name, account_code, currency_code, is_active) VALUES (?, ?, ?, "CZK", 1)'
        )->execute([$ctx->supplierId, mb_substr($name, 0, 100), $account]);
        $id = (int) $pdo->lastInsertId();
        $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_CASH_REGISTER, $code, $id, $ctx->runId);
        $ctx->protocol->count(self::STEP_CASH, 'registers');
        return $id;
    }

    /**
     * Skutečné číslo účtu firmy zná Money. Na měnový účet firmy (CZK, EUR…) se doplní účet
     * v té měně z POSLEDNÍHO roku agendy (banku mohla firma změnit) jen tehdy, když tam žádné
     * není — vyplněný účet převod nepřepisuje.
     *
     * @param array<string,array{number:string,bank:string,iban:string,currency:string,year:int}> $accounts
     */
    private function fillOwnAccount(int $supplierId, array $accounts): void
    {
        $best = [];
        foreach ($accounts as $a) {
            if ($a['number'] === '') {
                continue;
            }
            $current = $best[$a['currency']] ?? null;
            if ($current === null || $a['year'] > $current['year']) {
                $best[$a['currency']] = $a;
            }
        }
        $update = $this->db->pdo()->prepare(
            "UPDATE currencies SET account_number = ?, bank_code = ?, iban = COALESCE(iban, ?)
              WHERE supplier_id = ? AND code = ? AND (account_number IS NULL OR account_number = '')"
        );
        foreach ($best as $currency => $a) {
            $update->execute([
                mb_substr($a['number'], 0, 30),
                $a['bank'] !== '' ? mb_substr($a['bank'], 0, 4) : null,
                $a['iban'] !== '' ? mb_substr($a['iban'], 0, 34) : null,
                $supplierId,
                $currency,
            ]);
        }
    }

    /** Měna pokladny/účtu z Money (prázdná = domácí). */
    private static function currency(string $mena): string
    {
        $m = strtoupper(trim($mena));
        return in_array($m, ['', 'KČ', 'CZK'], true) ? 'CZK' : $m;
    }
}
