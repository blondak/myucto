<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Service\Bank\GpcParser;

/**
 * Výpisy KB ve formátu KM ze služby STATDA (varianta Basic). KM je GPC se dvěma
 * odchylkami: čísla účtů bývají ve „vnitřním formátu“ KB (přeházené číslice)
 * a pole měny v záznamu 075 KB neplní. Soubor nese jeden obchodní den; období
 * se skládá do jednoho výpisu stejně jako pohyby ADAA u varianty Plus.
 */
final class KbPlusKmStatementParser
{
    private const MAX_FILE_BYTES = 10 * 1024 * 1024;
    /** Pozice číslic N1…N16 edičního formátu ve vnitřním formátu (popis formátu KM, odd. 1.4). */
    private const INTERNAL_TO_EDITION = [10, 11, 12, 13, 14, 15, 4, 5, 6, 7, 8, 3, 9, 1, 2, 0];

    public function __construct(private readonly GpcParser $gpc = new GpcParser()) {}

    /**
     * Výpisy po obchodních dnech: surové bajty dne (záznam 074 s pohyby) a jejich
     * rozbor s čísly účtů v edičním tvaru. Každý den se ukládá jako originál výpisu
     * banky, takže opakované stažení téhož dne pozná import podle otisku souboru.
     *
     * @param list<string> $files
     * @return list<array{content:string,parsed:array{header:array<string,mixed>,transactions:list<array<string,mixed>>}}>
     */
    public function statements(#[\SensitiveParameter] array $files, string $iban, string $currency): array
    {
        $account = $this->editionAccount($iban);
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw $this->invalid('neplatná měna účtu');
        }
        $statements = [];
        foreach ($files as $file) {
            foreach ($this->blocks($file) as $block) {
                $parsed = $this->statement($block, $account, $currency);
                $parsed['header']['account_number'] = $account;
                $parsed['header']['currency'] = $currency;
                $statements[] = ['content' => implode("\r\n", $block) . "\r\n", 'parsed' => $parsed];
            }
        }
        usort($statements, static fn (array $a, array $b): int =>
            strcmp($a['parsed']['header']['statement_date'], $b['parsed']['header']['statement_date']));
        return $statements;
    }

    /**
     * Souhrn období pro kontrolu účtu při synchronizaci.
     *
     * @param list<string> $files
     * @return array{header:array<string,mixed>,transactions:list<array<string,mixed>>}
     */
    public function parse(
        #[\SensitiveParameter] array $files,
        string $iban,
        string $currency,
        string $from,
        string $to,
    ): array {
        if ($this->date($from, 'Y-m-d') > $this->date($to, 'Y-m-d')) {
            throw $this->invalid('neplatné období');
        }
        $statements = array_column($this->statements($files, $iban, $currency), 'parsed');
        $headers = array_column($statements, 'header');
        $first = $headers[0] ?? null;
        $last = $headers === [] ? null : $headers[array_key_last($headers)];

        return [
            'header' => [
                'account_number' => $iban,
                'statement_date' => $last['statement_date'] ?? $to,
                'statement_number' => count($headers) === 1 ? $first['statement_number'] : null,
                'prev_balance' => $first['prev_balance'] ?? null,
                'curr_balance' => $last['curr_balance'] ?? null,
                'debit_total' => $headers === [] ? null : round(array_sum(array_column($headers, 'debit_total')), 2),
                'credit_total' => $headers === [] ? null : round(array_sum(array_column($headers, 'credit_total')), 2),
            ],
            'transactions' => array_merge([], ...array_column($statements, 'transactions')),
        ];
    }

    /** @return list<list<string>> */
    private function blocks(#[\SensitiveParameter] string $file): array
    {
        $file = rtrim($file, "\x1A\r\n");
        if ($file === '' || strlen($file) > self::MAX_FILE_BYTES || preg_match('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', $file) === 1) {
            throw $this->invalid('soubor není ve formátu KM');
        }
        $blocks = [];
        foreach (preg_split('/\r\n|\n|\r/', $file) ?: [] as $line) {
            $type = substr($line, 0, 3);
            if ($type === '074') {
                $blocks[] = [$line];
                continue;
            }
            if ($blocks === [] || !in_array($type, ['075', '076', '078', '079'], true)) {
                throw $this->invalid('neočekávaný záznam');
            }
            $blocks[array_key_last($blocks)][] = $line;
        }
        if ($blocks === []) {
            throw $this->invalid('chybí záznam 074');
        }
        return $blocks;
    }

    /**
     * @param list<string> $lines
     * @return array{header:array<string,mixed>,transactions:list<array<string,mixed>>}
     */
    private function statement(#[\SensitiveParameter] array $lines, string $account, string $currency): array
    {
        $raw = substr($lines[0], 3, 16);
        if (strlen($lines[0]) < 114 || !preg_match('/^\d{16}$/D', $raw)) {
            throw $this->invalid('neplatný záznam 074');
        }
        $this->date(substr($lines[0], 108, 6), 'dmy');
        // Popis formátu uvádí vnitřní formát; ediční se přijme také, jiný účet ne.
        $internal = match (true) {
            $raw === $account => false,
            $this->toEdition($raw) === $account => true,
            default => throw $this->invalid('výpis patří k jinému účtu'),
        };
        foreach ($lines as $line) {
            if (str_starts_with($line, '075') && (
                strlen($line) < 128
                || substr($line, 3, 16) !== $raw
                || !preg_match('/^\d{16}$/D', substr($line, 19, 16))
                || !preg_match('/^\d{12}$/D', substr($line, 48, 12))
                || !in_array($line[60], ['1', '2', '4', '5'], true)
            )) {
                throw $this->invalid('neplatný záznam 075');
            }
        }

        $parsed = $this->gpc->parse(implode("\r\n", $lines));
        foreach ($parsed['transactions'] as &$transaction) {
            if ($transaction['posted_at'] === null) {
                throw $this->invalid('pohyb nemá platné datum');
            }
            $transaction['currency'] = $currency;
            if ($internal && $transaction['counterparty_account'] !== null) {
                $transaction['counterparty_account'] = $this->toEdition($transaction['counterparty_account']);
            }
        }
        unset($transaction);
        return $parsed;
    }

    private function editionAccount(string $iban): string
    {
        if (!preg_match('/^CZ\d{2}0100(\d{16})$/D', strtoupper($iban), $match)) {
            throw $this->invalid('výpis KM patří jen k českému účtu KB');
        }
        return $match[1];
    }

    private function toEdition(string $internal): string
    {
        $edition = '';
        foreach (self::INTERNAL_TO_EDITION as $position) {
            $edition .= $internal[$position];
        }
        return $edition;
    }

    private function date(string $value, string $format): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date === false || $date->format($format) !== $value) {
            throw $this->invalid('neplatné datum');
        }
        return $date;
    }

    private function invalid(string $reason): \RuntimeException
    {
        return new \RuntimeException("KB+ výpis KM: {$reason}.");
    }
}
