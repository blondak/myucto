<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Pdf\CreditCard;

use MyInvoice\Service\Bank\Pdf\BankStatementPdfParserInterface;
use MyInvoice\Service\Bank\Pdf\CsobStatementPdfParser;

/**
 * Výpis úvěrového účtu kreditní karty ČSOB.
 *
 * ČSOB tiskne kreditní účet stejným layoutem jako běžný účet („VÝPIS Z ÚČTU", Účet,
 * Počáteční/Konečný zůstatek, řádky s během zůstatku) - hlavičku i řetěz zůstatků proto
 * čte parser běžného účtu {@see CsobStatementPdfParser} a tahle třída jen doplní, co je
 * pro kreditní kartu jiné: typ transakce (podle něj se určuje druh pohybu), datum
 * transakce a původní částku z řádku „Částka: 654 CZK 04.08.2026", obchodníka z „Místo:"
 * a úvěrový limit.
 *
 * Odlišení od běžného účtu: blok „Informace k plánované splátce" / typ „Čerpání úvěru
 * platební kartou". Tenhle parser musí být v registru PŘED parserem běžného účtu, jinak by
 * se kreditní výpis naimportoval jako běžný účet (na 221).
 *
 * ČSOB v kreditním výpisu koncovku karty netiskne - nákupy jdou bez ní (mezičlen platebních
 * karet se proto neuplatní, účtují se přímo proti 231.x).
 */
final class CsobCreditCardStatementPdfParser implements BankStatementPdfParserInterface
{
    public function __construct(private readonly CsobStatementPdfParser $base) {}

    public function key(): string
    {
        return 'csob_credit_card';
    }

    public function supports(string $text): bool
    {
        return $this->base->supports($text)
            && (str_contains($text, 'Informace k plánované splátce')
                || str_contains($text, 'Čerpání úvěru platební kartou'));
    }

    public function parse(string $pdfBytes, string $text): array
    {
        $parsed = $this->base->parse($pdfBytes, $text);
        $header = $parsed['header'];
        $types = $this->types($text);
        if (count($types) !== count($parsed['transactions'])) {
            throw new \RuntimeException(sprintf(
                'ČSOB kreditní karta: počet typů transakcí (%d) nesedí na počet pohybů (%d). Import zamítnut.',
                count($types),
                count($parsed['transactions']),
            ));
        }
        $currency = (string) ($parsed['transactions'][0]['currency'] ?? (preg_match('/Měna:\s*([A-Z]{3})/u', $text, $c) ? $c[1] : 'CZK'));

        $transactions = [];
        foreach ($parsed['transactions'] as $i => $tx) {
            $txDate = null;
            $original = null;
            $details = [];
            foreach (explode(' | ', (string) ($tx['description'] ?? '')) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                // Parser běžného účtu končí tabulku až u „Prosíme Vás…", takže poslední pohyb
                // by si přibral souhrn splátky (úroky, splatnost) - ten k pohybu nepatří.
                if (str_starts_with($part, 'Informace k plánované splátce')) {
                    break;
                }
                if (preg_match('/^Částka:\s*([\d.,]+)\s*([A-Z]{3})\s+(\d{2})\.(\d{2})\.(\d{4})$/u', $part, $m)) {
                    $original = $m[1] . ' ' . $m[2];
                    $txDate = CreditCardPdfText::date($m[3], $m[4], $m[5]);
                    continue;
                }
                if (preg_match('/^[\d ]+$/u', $part)) {
                    continue; // VS/KS/SS karetní transakce slepené do jednoho čísla
                }
                $details[] = $part;
            }
            $foreign = $original !== null && !str_ends_with($original, ' ' . $currency) ? 'orig. ' . $original : null;
            $description = CreditCardPdfText::description($types[$i], $txDate, array_merge([$foreign], $details));
            $transactions[] = CreditCardPdfText::transaction(
                (string) $tx['posted_at'],
                (float) $tx['amount'],
                $currency,
                $description,
                $tx['counterparty_name'] ?? null,
                null,
                $tx['counterparty_account'] ?? null,
                $tx['counterparty_bank'] ?? null,
                $tx['bank_ref'] ?? null,
                $tx['variable_symbol'] ?? null,
            );
        }

        $bankCode = preg_match('/Účet:\s*[\d\-]+\/(\d{4})/u', $text, $b) ? $b[1] : '0300';
        $limit = preg_match('/Celkový limit:\s*(' . CreditCardPdfText::MONEY_COMMA . ')/u', $text, $l) ? CreditCardPdfText::money($l[1]) : null;
        $from = preg_match('/Období:\s*(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})\s*-/u', $text, $p) ? CreditCardPdfText::date($p[1], $p[2], $p[3]) : null;

        return [
            'header' => CreditCardPdfText::header(
                'csob',
                (string) $header['account_number'],
                $bankCode,
                (string) $header['statement_date'],
                (string) $header['statement_number'],
                (float) $header['prev_balance'],
                (float) $header['curr_balance'],
                $transactions,
                $currency,
                $limit,
                $from,
            ),
            'transactions' => $transactions,
        ];
    }

    /**
     * Typy transakcí v pořadí pohybů: text prvního řádku pohybu („DD.MM.Typ …") před
     * pořadovým číslem, protistranou a částkami. Bere jen řádky, které končí během
     * zůstatku - stejné pravidlo, podle kterého pohyb uznává parser běžného účtu.
     *
     * @return list<string>
     */
    private function types(string $text): array
    {
        $money = '-?\d{1,3}(?:[\x{00A0} ]\d{3})*,\d{2}';
        $out = [];
        $inTable = false;
        foreach (CreditCardPdfText::lines($text) as $line) {
            if (preg_match('/^Identifikace\s+Částka\s+Zůstatek$/u', $line)) {
                $inTable = true;
                continue;
            }
            if (!$inTable) {
                continue;
            }
            if (str_starts_with($line, 'Prosíme Vás')) {
                break;
            }
            if (!preg_match('/^\d{1,2}\.\d{1,2}\.(.*)$/u', $line, $m)) {
                continue;
            }
            if (!preg_match('/' . $money . '\s*$/u', $m[1])) {
                continue;
            }
            $type = trim((string) preg_split('/\t/u', $m[1])[0]);
            $type = trim((string) preg_replace('/\s+\d+\s+' . $money . '.*$/u', '', $type));
            $out[] = $type;
        }
        return $out;
    }
}
