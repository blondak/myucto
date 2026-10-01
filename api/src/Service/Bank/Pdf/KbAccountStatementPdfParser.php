<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Pdf;

/**
 * Parser PDF „Výpis z účtu" KB v novějším layoutu (internetové bankovnictví, podpis
 * šablony „VYPIS1_NDB"). Starší layout („VÝPIS PERIODICKÝ" / „VÝPIS DENNÍ PŘI
 * POHYBU") čte {@see KbStatementPdfParser}; oba nesou patičku Komerční banky, proto
 * tenhle parser musí být v registru před ním.
 *
 * Layout: hlavička „Výpis z účtu D. M. RRRR – D. M. RRRR", blok „Informace o účtu"
 * (číslo účtu, IBAN, hlavní měna), řádek zůstatků (počáteční a konečný) a sekce
 * „Transakce". Každý pohyb má dva řádky oddělené záhlavím detailu:
 *
 *   D. M. RRRR <název protistrany> TAB [<protiúčet>/<banka>] TAB <částka> <měna>
 *   Datum provedení Kód transakce Typ transakce Variabilní symbol Specifický symbol Konstantní symbol
 *   D. M. RRRR <kód> <typ transakce…> <VS> TAB <SS> TAB <KS>     („-" = prázdné)
 *   [další řádky: pokračování typu, zpráva pro příjemce]
 *
 * Datum prvního řádku je datum zaúčtování: pohyb do výpisu patří jím (u poplatků
 * bývá datum provedení o den dřív). Kód transakce je identifikátor pohybu u banky.
 *
 * Výpis nenese obraty, jen zůstatky a počet transakcí; obojí se ověřuje (self-check),
 * jinak se parsování zamítne. Víceměnový výpis se odmítne celý, protože pohyby jiné měny
 * než hlavní by se jinak sečetly do zůstatku v korunách.
 */
final class KbAccountStatementPdfParser implements BankStatementPdfParserInterface
{
    private const MONEY = '-?\d{1,3}(?:[\x{00A0} ]\d{3})*,\d{2}';
    private const DATE = '(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})';
    private const DETAIL_HEADER = 'Datum provedení Kód transakce Typ transakce';

    /** Řádky stránkové hlavičky a patičky, které se mohou objevit uprostřed pohybů. */
    private const SKIP_LINE_PATTERNS = [
        '/^Datum výpisu$/u',
        '/^\d+\/\d+$/u',
        '/^Komerční banka, a\. s\./u',
        '/^Zapsaná v obchodním rejstříku/u',
        '/^Id:/u',
        '/^Code:/u',
        '/^Úroková sazba/u',
    ];

    public function key(): string
    {
        return 'kb_account';
    }

    public function supports(string $text): bool
    {
        // Záhlaví detailu pohybu tu být nemusí (měsíc bez pohybů), rozhoduje hlavička.
        $text = $this->normalize($text);
        return str_contains($text, 'Komerční banka')
            && preg_match('/Číslo účtu\s+[\d\-]+\/0100/u', $text) === 1
            && preg_match('/Výpis z účtu\s+' . self::DATE . '\s*[–-]\s*' . self::DATE . '/u', $text) === 1;
    }

    public function parse(string $pdfBytes, string $text): array
    {
        $header = $this->parseHeaderFromText($text);
        $transactions = $this->parseTransactionsFromText($text);
        $currency = (string) $header['currency'];

        foreach ($transactions as $tx) {
            if ($tx['currency'] !== $currency) {
                throw new \RuntimeException(sprintf(
                    'KB PDF: pohyb v měně %s na výpisu účtu v %s, víceměnový výpis zatím nelze načíst.',
                    $tx['currency'],
                    $currency,
                ));
            }
        }
        if ($header['transaction_count'] !== null && $header['transaction_count'] !== count($transactions)) {
            throw new \RuntimeException(sprintf(
                'KB PDF: výpis uvádí %d transakcí, přečteno %d. Parsování zamítnuto.',
                $header['transaction_count'],
                count($transactions),
            ));
        }

        $credit = 0.0;
        $debit = 0.0;
        foreach ($transactions as $tx) {
            if ($tx['amount'] > 0) {
                $credit += $tx['amount'];
            } else {
                $debit -= $tx['amount'];
            }
        }
        $expected = round($header['curr_balance'] - $header['prev_balance'], 2);
        if (abs(round($credit - $debit, 2) - $expected) > 0.001) {
            throw new \RuntimeException(sprintf(
                'KB PDF: součet transakcí (%.2f) nesedí na změnu zůstatku dle hlavičky (%.2f). Parsování zamítnuto.',
                $credit - $debit,
                $expected,
            ));
        }

        unset($header['currency'], $header['transaction_count']);
        $header['credit_total'] = round($credit, 2);
        $header['debit_total'] = round($debit, 2);
        $header['account_currency'] = $currency;

        return ['header' => $header, 'transactions' => $transactions];
    }

    /**
     * @return array{account_number:string, statement_date:string, statement_number:string,
     *   prev_balance:float, curr_balance:float, debit_total:float, credit_total:float,
     *   currency:string, period_kind:string, transaction_count:?int}
     */
    public function parseHeaderFromText(string $text): array
    {
        $text = $this->normalize($text);

        if (!preg_match('/Číslo účtu\s+([\d\-]+)\/(\d{4})/u', $text, $m)) {
            throw new \RuntimeException('KB PDF: chybí číslo účtu v hlavičce.');
        }
        $accountNumber = $m[1];

        if (!preg_match('/Výpis z účtu\s+' . self::DATE . '\s*[–-]\s*' . self::DATE . '/u', $text, $m)) {
            throw new \RuntimeException('KB PDF: chybí období výpisu („Výpis z účtu … – …").');
        }
        $statementDate = $this->date($m[4], $m[5], $m[6]);

        $currency = preg_match('/Hlavní měna účtu\s+(\S+)/u', $text, $m) ? $this->currency($m[1]) : null;
        if ($currency === null) {
            throw new \RuntimeException('KB PDF: chybí hlavní měna účtu.');
        }

        if (!preg_match('/Počáteční zůstatek\s*\tKonečný zůstatek[^\n]*\n([^\n]*)/u', $text, $m)) {
            throw new \RuntimeException('KB PDF: chybí řádek zůstatků.');
        }
        if (preg_match_all('/(' . self::MONEY . ')\s*(\S+)/u', $m[1], $balances, PREG_SET_ORDER) !== 2) {
            throw new \RuntimeException('KB PDF: řádek zůstatků nemá počáteční a konečný zůstatek.');
        }
        foreach ($balances as $balance) {
            if ($this->currency($balance[2]) !== $currency) {
                throw new \RuntimeException('KB PDF: zůstatek není v hlavní měně účtu.');
            }
        }

        return [
            'account_number'    => $accountNumber,
            'statement_date'    => $statementDate,
            'statement_number'  => '',
            'prev_balance'      => $this->num($balances[0][1]),
            'curr_balance'      => $this->num($balances[1][1]),
            'debit_total'       => 0.0,
            'credit_total'      => 0.0,
            'currency'          => $currency,
            'period_kind'       => 'period',
            'transaction_count' => preg_match('/Celkový počet transakcí\s*(\d+)/u', $text, $m) ? (int) $m[1] : null,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function parseTransactionsFromText(string $text): array
    {
        $lines = preg_split('/\n/u', $this->normalize($text)) ?: [];
        $start = null;
        foreach ($lines as $i => $line) {
            if (trim($line) === 'Transakce') {
                $start = $i + 1;
                break;
            }
        }
        if ($start === null) {
            return [];
        }

        $rows = [];
        $current = null;
        $detail = [];
        for ($i = $start, $n = count($lines); $i < $n; $i++) {
            $line = trim($lines[$i]);
            if ($line === '' || $this->isSkipLine($line)) continue;
            if (str_starts_with($line, 'Celkový počet transakcí')) break;

            $main = $this->mainLine($line);
            if ($main !== null) {
                if ($current !== null) $rows[] = $this->finish($current, $detail);
                $current = $main;
                $detail = [];
                continue;
            }
            if ($current === null) continue;
            if (str_starts_with($line, self::DETAIL_HEADER)) continue;
            $detail[] = $line;
        }
        if ($current !== null) $rows[] = $this->finish($current, $detail);

        return $rows;
    }

    /**
     * Hlavní řádek pohybu: datum, název protistrany, volitelně protiúčet, částka s měnou.
     *
     * @return array<string,mixed>|null
     */
    private function mainLine(string $line): ?array
    {
        if (!preg_match('/^' . self::DATE . '\s+(.*)$/u', $line, $m)) {
            return null;
        }
        $cells = array_map('trim', explode("\t", $m[4]));
        if (count($cells) < 2 || !preg_match('/^(' . self::MONEY . ')\s*(\S+)$/u', $cells[count($cells) - 1], $amount)) {
            return null;
        }
        $currency = $this->currency($amount[2]);
        if ($currency === null) {
            return null;
        }
        $account = null;
        $bank = null;
        if (count($cells) >= 3) {
            $cell = $cells[count($cells) - 2];
            if (preg_match('/^([\d\-]+)\/(\d{4})$/', $cell, $acc)) {
                $account = $acc[1];
                $bank = $acc[2];
            } elseif (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $cell)) {
                $account = $cell;
            }
        }
        $name = trim($cells[0]);

        return [
            'posted_at'            => $this->date($m[1], $m[2], $m[3]),
            'amount'               => round($this->num($amount[1]), 2),
            'currency'             => $currency,
            'counterparty_account' => $account,
            'counterparty_bank'    => $bank,
            'counterparty_name'    => $name !== '' ? mb_substr($name, 0, 190) : null,
        ];
    }

    /**
     * Detail pohybu: kód transakce, typ, symboly (VS TAB SS TAB KS) a zpráva.
     *
     * @param array<string,mixed> $row
     * @param list<string> $detail
     * @return array<string,mixed>
     */
    private function finish(array $row, array $detail): array
    {
        $reference = null;
        $vs = null; $ss = null; $ks = null;
        // Typ transakce se láme do víc řádků až po řádek se symboly; co je za ním,
        // je zpráva pro příjemce a další poznámky.
        $type = [];
        $notes = [];
        $symbolsFound = false;
        foreach ($detail as $index => $line) {
            if ($index === 0 && preg_match('/^' . self::DATE . '\s+(\S+)\s*(.*)$/u', $line, $m)) {
                $reference = $m[4];
                $line = $m[5];
            }
            if (!$symbolsFound && substr_count($line, "\t") === 2) {
                [$before, $ssCell, $ksCell] = array_map('trim', explode("\t", $line));
                $tokens = preg_split('/\s+/u', $before) ?: [];
                $vs = $this->symbol((string) array_pop($tokens));
                $ss = $this->symbol($ssCell);
                $ks = $this->symbol($ksCell);
                $rest = trim(implode(' ', $tokens));
                if ($rest !== '') $type[] = $rest;
                $symbolsFound = true;
                continue;
            }
            $line = trim($line);
            if ($line === '') continue;
            if ($symbolsFound) {
                $notes[] = $line;
            } else {
                $type[] = $line;
            }
        }
        $parts = array_values(array_filter([implode(' ', $type), ...$notes], static fn (string $p): bool => $p !== ''));

        return $row + [
            'variable_symbol' => $vs,
            'constant_symbol' => $ks,
            'specific_symbol' => $ss,
            'description'     => $parts !== [] ? mb_substr(implode(' | ', $parts), 0, 255) : null,
            'bank_ref'        => $reference,
        ];
    }

    private function symbol(string $cell): ?string
    {
        $cell = trim($cell);
        if (!preg_match('/^\d+$/', $cell)) {
            return null;
        }
        $value = ltrim($cell, '0');
        return $value !== '' ? $value : null;
    }

    private function isSkipLine(string $line): bool
    {
        foreach (self::SKIP_LINE_PATTERNS as $pattern) {
            if (preg_match($pattern, $line)) return true;
        }
        return preg_match('/^' . self::DATE . '$/u', $line) === 1;
    }

    private function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
    }

    private function currency(string $symbol): ?string
    {
        $symbol = trim($symbol);
        if ($symbol === 'Kč') return 'CZK';
        return preg_match('/^[A-Z]{3}$/', $symbol) === 1 ? $symbol : null;
    }

    private function date(string $day, string $month, string $year): string
    {
        $d = (int) $day;
        $m = (int) $month;
        $y = (int) $year;
        if (!checkdate($m, $d, $y)) {
            throw new \RuntimeException('KB PDF: neplatné datum.');
        }
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    private function num(string $value): float
    {
        return (float) str_replace([' ', ','], ['', '.'], $value);
    }
}
