<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Pdf;

/**
 * Parser PDF „Výpis z běžného účtu" MONETA Money Bank.
 *
 * Layout: v hlavičce nejdřív blok popisků, pak blok hodnot (číslo výpisu „RRRR/N",
 * datum výpisu, strana, periodicita), bankovní spojení „<číslo> / 0600", měna účtu
 * a souhrn zúčtování. Tabulka „Přehled transakcí" má na pohyb tři řádky a poznámky:
 *
 *   DD.MM.RRRR [<protiúčet>/<banka> | popis] TAB <kód transakce> TAB [<VS>] TAB <částka>
 *   <název protistrany> TAB <datum zaúčtování> TAB [<KS>]
 *   <valuta> [TAB <SS>]
 *   [AV: zpráva pro příjemce, DI: …]
 *
 * Částka stojí buď ve sloupci „Debetní obrat", nebo „Kreditní obrat", jenže textová
 * vrstva PDF sloupec neuchová a částku tiskne bez znaménka. Směr pohybu se proto
 * odvodí z obratů, které výpis uvádí („Součet obratů na výpisu: <debet> <kredit>"):
 * pohyby se rozdělí tak, aby součet kreditů dal kreditní a součet debetů debetní obrat.
 * Když je takové rozdělení víc než jedno (nebo žádné), parsování se zamítne; směr
 * pohybu se nikdy neodhaduje. Nese-li částka znaménko, platí znaménko a obraty ho
 * jen ověří. Kontroluje se i počet transakcí a změna zůstatku.
 */
final class MonetaStatementPdfParser implements BankStatementPdfParserInterface
{
    private const MONEY = '-?\d{1,3}(?:[\x{00A0} ]\d{3})*,\d{2}';
    private const DATE_LINE = '/^(\d{2})\.(\d{2})\.(\d{4})(?:\s+(.*))?$/u';

    /** Nejvíc stavů dělení pohybů na kredit/debet; nad tím se rozdělení nehledá. */
    private const MAX_SPLIT_STATES = 200000;

    public function key(): string
    {
        return 'moneta';
    }

    public function supports(string $text): bool
    {
        $text = $this->normalize($text);
        return (str_contains($text, 'MONETA Money Bank') || str_contains($text, 'AGBACZPP'))
            && str_contains($text, 'Výpis z běžného účtu')
            && str_contains($text, 'Přehled transakcí');
    }

    public function parse(string $pdfBytes, string $text): array
    {
        $header = $this->parseHeaderFromText($text);
        $rows = $this->parseTransactionsFromText($text);

        if ($header['transaction_count'] !== null && $header['transaction_count'] !== count($rows)) {
            throw new \RuntimeException(sprintf(
                'MONETA PDF: výpis uvádí %d transakcí, přečteno %d. Parsování zamítnuto.',
                $header['transaction_count'],
                count($rows),
            ));
        }
        $expected = round($header['curr_balance'] - $header['prev_balance'], 2);
        if (abs(round($header['credit_total'] - $header['debit_total'], 2) - $expected) > 0.001) {
            throw new \RuntimeException('MONETA PDF: obraty nesedí na změnu zůstatku. Parsování zamítnuto.');
        }

        $credits = $this->creditRows($rows, $header['credit_total'], $header['debit_total']);
        $currency = (string) $header['currency'];
        $transactions = [];
        foreach ($rows as $index => $row) {
            $amount = $row['unsigned_amount'];
            unset($row['unsigned_amount'], $row['signed']);
            $row['amount'] = isset($credits[$index]) ? $amount : -$amount;
            $row['currency'] = $currency;
            $transactions[] = $row;
        }

        unset($header['currency'], $header['transaction_count']);
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
        $headPart = explode('Přehled transakcí', $text, 2)[0];

        if (!preg_match('/^([\d\-]+)\s*\/\s*(0600)\s*$/mu', $headPart, $m)) {
            throw new \RuntimeException('MONETA PDF: chybí číslo účtu v hlavičce.');
        }
        $accountNumber = $m[1];

        $statementNumber = preg_match('/^\d{4}\/(\d+)\s*$/mu', $headPart, $m) ? $m[1] : '';
        if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s*$/mu', $headPart, $m)) {
            throw new \RuntimeException('MONETA PDF: chybí datum výpisu.');
        }
        $statementDate = $this->date($m[1], $m[2], $m[3]);

        if (!preg_match('/Označení měny:\s*([A-Z]{3})/u', $text, $m)) {
            throw new \RuntimeException('MONETA PDF: chybí měna účtu.');
        }
        $currency = $m[1];

        if (!preg_match('/Počáteční zůstatek\t\s*(' . self::MONEY . ')/u', $text, $m)) {
            throw new \RuntimeException('MONETA PDF: chybí počáteční zůstatek.');
        }
        $prev = $this->num($m[1]);
        if (!preg_match('/^\s*Konečný zůstatek\s+(' . self::MONEY . ')\s*$/mu', $text, $m)) {
            throw new \RuntimeException('MONETA PDF: chybí konečný zůstatek.');
        }
        $curr = $this->num($m[1]);
        if (!preg_match('/Součet obratů na výpisu:\s*(' . self::MONEY . ')\s+(' . self::MONEY . ')/u', $text, $m)) {
            throw new \RuntimeException('MONETA PDF: chybí součet obratů.');
        }

        return [
            'account_number'    => $accountNumber,
            'statement_date'    => $statementDate,
            'statement_number'  => $statementNumber,
            'prev_balance'      => $prev,
            'curr_balance'      => $curr,
            'debit_total'       => abs($this->num($m[1])),
            'credit_total'      => abs($this->num($m[2])),
            'currency'          => $currency,
            'period_kind'       => 'period',
            'transaction_count' => preg_match('/Celkový počet transakcí:\s*(\d+)/u', $text, $c) ? (int) $c[1] : null,
        ];
    }

    /**
     * Pohyby s částkou BEZ znaménka (`unsigned_amount`), směr určí {@see parse()}.
     *
     * @return list<array<string,mixed>>
     */
    public function parseTransactionsFromText(string $text): array
    {
        $lines = explode("\n", $this->normalize($text));
        $inTable = false;
        $blocks = [];
        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '') continue;
            if (!$inTable) {
                if (str_starts_with($line, 'Valuta') && str_contains($line, 'SS')) $inTable = true;
                continue;
            }
            if (str_starts_with($line, 'Celkový počet transakcí')) break;
            if (preg_match(self::DATE_LINE, $line, $m) && ($m[4] ?? '') !== '' && $this->amountCell($m[4]) !== null) {
                $blocks[] = [$line];
                continue;
            }
            if ($blocks !== []) $blocks[array_key_last($blocks)][] = $line;
        }

        return array_map(fn (array $block): array => $this->row($block), $blocks);
    }

    /** @param non-empty-list<string> $block */
    private function row(array $block): array
    {
        preg_match(self::DATE_LINE, $block[0], $m);
        $cells = array_map('trim', explode("\t", (string) $m[4]));
        $amountText = (string) array_pop($cells);

        $account = null;
        $bank = null;
        $text = [];
        $first = $cells[0] ?? '';
        if (preg_match('/^([\d\-]+)\/(\d{4})$/', $first, $acc)) {
            $account = $acc[1];
            $bank = $acc[2];
        } elseif (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $first)) {
            $account = $first;
        } elseif ($first !== '') {
            $text[] = $first;
        }
        $reference = isset($cells[1]) && $cells[1] !== '' ? $cells[1] : null;
        $vs = $this->symbol($cells[2] ?? '');

        $name = null;
        $ks = null;
        $ss = null;
        $notes = [];
        foreach (array_slice($block, 1) as $index => $line) {
            $parts = array_map('trim', explode("\t", $line));
            if ($index === 0 && count($parts) >= 2 && preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $parts[1])) {
                $name = $parts[0] !== '' ? $parts[0] : null;
                $ks = $this->symbol($parts[2] ?? '');
                continue;
            }
            if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $parts[0])) {
                $ss ??= $this->symbol($parts[1] ?? '');
                continue;
            }
            $notes[] = $line;
        }
        $description = array_values(array_filter([...$text, ...$notes], static fn (string $p): bool => $p !== ''));

        return [
            'posted_at'            => $this->date($m[1], $m[2], $m[3]),
            'unsigned_amount'      => abs($this->num($amountText)),
            'signed'               => str_starts_with($amountText, '-'),
            'variable_symbol'      => $vs,
            'constant_symbol'      => $ks,
            'specific_symbol'      => $ss,
            'counterparty_account' => $account,
            'counterparty_bank'    => $bank,
            'counterparty_name'    => $name !== null ? mb_substr($name, 0, 190) : null,
            'description'          => $description !== [] ? mb_substr(implode(' | ', $description), 0, 255) : null,
            'bank_ref'             => $reference,
        ];
    }

    /**
     * Indexy kreditních pohybů. Jediné rozdělení, při kterém kredity dají kreditní
     * obrat a debety debetní; jinak výjimka.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<int,true>
     */
    private function creditRows(array $rows, float $creditTotal, float $debitTotal): array
    {
        $cents = array_map(static fn (array $r): int => (int) round($r['unsigned_amount'] * 100), $rows);
        $creditCents = (int) round($creditTotal * 100);
        $debitCents = (int) round($debitTotal * 100);
        if (array_sum($cents) !== $creditCents + $debitCents) {
            throw new \RuntimeException('MONETA PDF: součet pohybů nesedí na obraty výpisu. Parsování zamítnuto.');
        }
        // Výpis, který směr vyznačí znaménkem, se drží znaménka a obraty ho jen ověří.
        if (array_filter($rows, static fn (array $r): bool => !empty($r['signed'])) !== []) {
            $credits = array_filter($rows, static fn (array $r): bool => empty($r['signed']));
            $sum = array_sum(array_map(static fn (int $i): int => $cents[$i], array_keys($credits)));
            if ($sum !== $creditCents) {
                throw new \RuntimeException('MONETA PDF: kreditní pohyby nesedí na kreditní obrat. Parsování zamítnuto.');
            }
            return array_fill_keys(array_keys($credits), true);
        }
        if ($debitCents === 0) {
            return array_fill_keys(array_keys($rows), true);
        }
        if ($creditCents === 0) {
            return [];
        }

        // Počet způsobů (do 2), jak z prvních i pohybů složit součet s; stačí vědět,
        // jestli je rozdělení jediné, a pak ho zpětně dohledat.
        $ways = [0 => [0 => 1]];
        foreach ($cents as $i => $value) {
            $next = $ways[$i];
            foreach ($ways[$i] as $sum => $count) {
                $target = $sum + $value;
                if ($target > $creditCents) continue;
                $next[$target] = min(2, ($next[$target] ?? 0) + $count);
            }
            if (count($next) > self::MAX_SPLIT_STATES) {
                throw new \RuntimeException('MONETA PDF: směr pohybů nelze z obratů jednoznačně určit. Nahrajte výpis ve formátu GPC.');
            }
            $ways[$i + 1] = $next;
        }
        if (($ways[count($cents)][$creditCents] ?? 0) !== 1) {
            throw new \RuntimeException('MONETA PDF: směr pohybů nelze z obratů jednoznačně určit. Nahrajte výpis ve formátu GPC.');
        }
        $credits = [];
        $sum = $creditCents;
        for ($i = count($cents) - 1; $i >= 0; $i--) {
            if (($ways[$i][$sum] ?? 0) === 0) {
                $credits[$i] = true;
                $sum -= $cents[$i];
            }
        }
        return $credits;
    }

    private function amountCell(string $rest): ?string
    {
        $cells = explode("\t", $rest);
        $last = trim((string) end($cells));
        return count($cells) >= 2 && preg_match('/^' . self::MONEY . '$/u', $last) ? $last : null;
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

    private function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
    }

    private function date(string $day, string $month, string $year): string
    {
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            throw new \RuntimeException('MONETA PDF: neplatné datum.');
        }
        return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
    }

    private function num(string $value): float
    {
        return (float) str_replace([' ', ','], ['', '.'], $value);
    }
}
