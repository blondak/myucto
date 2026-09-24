<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Pdf\CreditCard;

use MyInvoice\Service\Bank\Card\CardNumberMask;
use MyInvoice\Service\Bank\Pdf\BankStatementPdfParserInterface;

/**
 * Výpis z kartového účtu Raiffeisenbank („VÝPIS Z KARTOVÉHO ÚČTU").
 *
 * RB číslo úvěrového účtu netiskne. Účet identifikuje „Referenční číslo karty" (kód banky
 * 5500) - to je stálé pro celý kartový účet a na výpisech se opakuje. Splátka jde na sběrný
 * účet banky s variabilním symbolem; obojí parser vrátí v hlavičce, ať se dá předvyplnit
 * u úvěrového účtu.
 *
 * Souhrn „Předchozí stav / Připsáno / Čerpáno / Aktuální stav + Splátkové programy =
 * Celková dlužná částka" nese dluh jako záporné číslo. Tabulka je po kartách („Číslo
 * a název karty: 531533xxxxxx1234"); řádek pohybu začíná datem transakce a (u karetních
 * plateb) datem zaúčtování, následuje obchodník, typ transakce a částka. Splátka a poplatek
 * mají jen jedno datum.
 *
 * Kontrola: součet pohybů = Aktuální stav − Předchozí stav a zároveň připsáno/čerpáno
 * sedí na řádek „Celkem za kartový účet".
 */
final class RaiffeisenbankCreditCardStatementPdfParser implements BankStatementPdfParserInterface
{
    private const M = CreditCardPdfText::MONEY_RB;

    private const DATE = '(\d{1,2})\.\s?(\d{1,2})\.\s?(\d{4})';

    public function key(): string
    {
        return 'raiffeisenbank_credit_card';
    }

    public function supports(string $text): bool
    {
        return str_contains($text, 'VÝPIS Z KARTOVÉHO ÚČTU') && str_contains($text, 'Raiffeisenbank');
    }

    public function parse(string $pdfBytes, string $text): array
    {
        if (!preg_match('/Referenční číslo karty:\s*([\d][\d-]+)/u', $text, $m)) {
            throw new \RuntimeException('RB kreditní karta: chybí referenční číslo karty.');
        }
        $account = $m[1];

        if (!preg_match('/(?:Zúčtovací období|Výpis za období):?\s*' . self::DATE . '\s*-\s*' . self::DATE . '/u', $text, $p)) {
            throw new \RuntimeException('RB kreditní karta: chybí zúčtovací období.');
        }
        $from = CreditCardPdfText::date($p[1], $p[2], $p[3]);
        $to = CreditCardPdfText::date($p[4], $p[5], $p[6]);
        if ($to === null) {
            throw new \RuntimeException('RB kreditní karta: neplatné zúčtovací období.');
        }
        $currency = preg_match('/Připsáno \(([A-Z]{3})\)/u', $text, $c) ? $c[1] : 'CZK';
        $limit = preg_match('/Úvěrový limit:\s*(' . self::M . ')/u', $text, $l) ? CreditCardPdfText::money($l[1]) : null;

        if (!preg_match('/(' . self::M . ')\s+(' . self::M . ')\s+(' . self::M . ')\s+(' . self::M . ')\s*\+\s*(' . self::M . ')\s*=\s*(' . self::M . ')/u', $text, $s)) {
            throw new \RuntimeException('RB kreditní karta: chybí přehled čerpání (předchozí a aktuální stav).');
        }
        $prev = CreditCardPdfText::money($s[1]);
        $curr = CreditCardPdfText::money($s[4]);

        $transactions = $this->transactions($text, $currency);
        CreditCardPdfText::assertBalanced('RB', $transactions, $prev, $curr);
        if (preg_match('/Celkem za kartový účet\s*(' . self::M . ')\s+(' . self::M . ')/u', $text, $t)) {
            $credited = 0.0;
            $drawn = 0.0;
            foreach ($transactions as $tx) {
                if ((float) $tx['amount'] > 0) {
                    $credited += (float) $tx['amount'];
                } else {
                    $drawn += (float) $tx['amount'];
                }
            }
            if (abs(round($credited, 2) - CreditCardPdfText::money($t[1])) > 0.005
                || abs(round($drawn, 2) - CreditCardPdfText::money($t[2])) > 0.005) {
                throw new \RuntimeException('RB kreditní karta: připsáno/čerpáno nesedí na „Celkem za kartový účet". Import zamítnut.');
            }
        }

        $header = CreditCardPdfText::header('rb', $account, '5500', $to, '', $prev, $curr, $transactions, $currency, $limit, $from);
        if (preg_match('/Číslo účtu pro splátku\s*((?:\d{1,6}-)?\d{2,10})\/(\d{4})/u', $text, $r)) {
            $header['repayment_account'] = $r[1];
            $header['repayment_bank_code'] = $r[2];
        }
        if (preg_match('/Variabilní symbol\s*(\d{1,10})/u', $text, $v)) {
            $header['repayment_vs'] = $v[1];
        }
        return ['header' => $header, 'transactions' => $transactions];
    }

    /** @return list<array<string,mixed>> */
    public function transactions(string $text, string $currency = 'CZK'): array
    {
        $lines = CreditCardPdfText::lines($text);
        $start = null;
        foreach ($lines as $i => $line) {
            if ($line === 'Přehled transakcí') {
                $start = $i + 1;
                break;
            }
        }
        if ($start === null) {
            throw new \RuntimeException('RB kreditní karta: chybí přehled transakcí.');
        }

        $out = [];
        $card = null;
        $block = null;
        $flush = function () use (&$block, &$out, $currency): void {
            if ($block !== null) {
                $tx = $this->block($block, $currency);
                if ($tx !== null) {
                    $out[] = $tx;
                }
            }
            $block = null;
        };
        for ($i = $start, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];
            if (str_starts_with($line, 'Celkem za kartový účet')) {
                break;
            }
            if (str_starts_with($line, 'Celkem za kartu')) {
                $flush();
                continue;
            }
            if (preg_match('/^Číslo a název karty:\s*(\S+)/u', $line, $m)) {
                $flush();
                $card = CardNumberMask::last4FromText($m[1]);
                continue;
            }
            if ($this->isNoise($line)) {
                continue;
            }
            if (preg_match('/^' . self::DATE . '\s*(?:' . self::DATE . ')?\s*(.*)$/u', $line, $m)) {
                $flush();
                $first = CreditCardPdfText::date($m[1], $m[2], $m[3]);
                $second = ($m[4] ?? '') !== '' ? CreditCardPdfText::date($m[4], $m[5], $m[6]) : null;
                $block = ['tx_date' => $first, 'posted' => $second ?? $first, 'card' => $card, 'texts' => [], 'amount' => null];
                $rest = trim($m[7]);
                if (preg_match('/^(.*?)\s*(' . self::M . ')$/u', $rest, $a)) {
                    $block['amount'] = CreditCardPdfText::money($a[2]);
                    $rest = trim($a[1]);
                }
                if ($rest !== '') {
                    $block['texts'][] = $rest;
                }
                continue;
            }
            if ($block === null) {
                continue;
            }
            if (preg_match('/^(.*?)\s*(' . self::M . ')$/u', $line, $a)) {
                $block['amount'] = CreditCardPdfText::money($a[2]);
                if (trim($a[1]) !== '') {
                    $block['texts'][] = trim($a[1]);
                }
                continue;
            }
            $block['texts'][] = $line;
        }
        $flush();
        return $out;
    }

    private function isNoise(string $line): bool
    {
        foreach (['VÝPIS Z KARTOVÉHO ÚČTU', 'Výpis za období', 'Strana ', 'Raiffeisenbank a.s.', 'Držitel karty:',
            'Popis transakce', 'Připsáno (', 'Datum', 'transakce', 'zaúčtování'] as $prefix) {
            if (str_starts_with($line, $prefix)) {
                return true;
            }
        }
        return preg_match('/^K\d+ v[\d.]+/u', $line) === 1;
    }

    /** @param array{tx_date:?string, posted:?string, card:?string, texts:list<string>, amount:?float} $block */
    private function block(array $block, string $currency): ?array
    {
        if ($block['posted'] === null || $block['amount'] === null || abs($block['amount']) < 0.005) {
            return null;
        }
        $texts = $block['texts'];
        // Karetní platba: obchodník, pak typ transakce. Splátka: jen typ.
        $type = count($texts) >= 2 ? $texts[1] : ($texts[0] ?? '');
        $first = count($texts) >= 2 ? $texts[0] : null;
        $rest = array_slice($texts, 2);
        $kind = \MyInvoice\Service\Bank\CreditCard\CreditCardTransactionKind::classify($type, $block['amount']);
        $isCard = in_array($kind, \MyInvoice\Service\Bank\CreditCard\CreditCardTransactionKind::CARD_KINDS, true);
        // U karetní platby je první řádek obchodník (jde do protistrany), jinak je to detail.
        $description = CreditCardPdfText::description($type, $block['tx_date'], array_merge($isCard ? [] : [$first], $rest));
        return CreditCardPdfText::transaction(
            $block['posted'],
            $block['amount'],
            $currency,
            $description,
            $isCard ? $first : null,
            $block['card'],
        );
    }
}
