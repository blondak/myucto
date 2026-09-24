<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Pdf\CreditCard;

use MyInvoice\Service\Bank\Card\CardNumberMask;
use MyInvoice\Service\Bank\Pdf\BankStatementPdfParserInterface;

/**
 * Výpis z kartového účtu České spořitelny / ERSTE („Výpis z kartového účtu").
 *
 * Částky s desetinnou TEČKOU a mezerou jako oddělovačem tisíců („-1 208.92", „+14 817.62").
 * Hlavička: „Číslo účtu/kód banky: 11180-…/0800", „Období: d.m.yyyy - d.m.yyyy",
 * „Číslo výpisu:", „Výše úvěru:"; „POČÁTEČNÍ ZŮSTATEK:" a „KONEČNÝ ZŮSTATEK:" (záporné =
 * dluh). Tabulka po kartách („KARTA ČÍSLO 4405 07xx xxxx 1234"), pohyb:
 *
 *   13.08.2026 Platba kartou Prague          Obchodník
 *   XXXXXXXXXXXX1234 d.tran.11.08.2026
 *   -657.00
 *
 * U cizoměnové platby stojí před částkou ještě částka ve sloupci „Cizí měna" (s kódem
 * měny). Splátka má protiúčet přímo v řádku: „Splátka 000000-1234567890 / 0800 +1 000.00".
 * Patičky stránek (identifikátory tisku, číslo stránky) se mezi pohyby ignorují - do bloku
 * pohybu se berou jen řádky, které tvarem odpovídají kartě / datu transakce / částce.
 */
final class ErsteCreditCardStatementPdfParser implements BankStatementPdfParserInterface
{
    private const M = CreditCardPdfText::MONEY_DOT;

    public function key(): string
    {
        return 'erste_credit_card';
    }

    public function supports(string $text): bool
    {
        return str_contains($text, 'Výpis z kartového účtu')
            && (str_contains($text, 'Česká spořitelna') || str_contains($text, 'GIBACZPX') || str_contains($text, 'Erste'));
    }

    public function parse(string $pdfBytes, string $text): array
    {
        if (!preg_match('/Číslo účtu\/kód banky:\s*((?:\d{1,6}-)?\d{2,10})\/(\d{4})/u', $text, $m)) {
            throw new \RuntimeException('ERSTE kreditní karta: chybí číslo účtu.');
        }
        $account = $m[1];
        $bankCode = $m[2];
        if (!preg_match('/Období:\s*(\d{1,2})\.(\d{1,2})\.(\d{4})\s*-\s*(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $text, $p)) {
            throw new \RuntimeException('ERSTE kreditní karta: chybí období výpisu.');
        }
        $from = CreditCardPdfText::date($p[1], $p[2], $p[3]);
        $to = CreditCardPdfText::date($p[4], $p[5], $p[6]);
        if ($to === null) {
            throw new \RuntimeException('ERSTE kreditní karta: neplatné období výpisu.');
        }
        $number = preg_match('/Číslo výpisu:\s*(\d+)/u', $text, $n) ? (ltrim($n[1], '0') ?: '0') : '';
        $currency = preg_match('/Měna účtu:\s*([A-Z]{3})/u', $text, $c) ? $c[1] : 'CZK';
        $limit = preg_match('/Výše úvěru:\s*(' . self::M . ')/u', $text, $l) ? CreditCardPdfText::money($l[1], '.') : null;
        if (!preg_match('/POČÁTEČNÍ ZŮSTATEK:\s*(' . self::M . ')/u', $text, $a)
            || !preg_match('/KONEČNÝ ZŮSTATEK:\s*(' . self::M . ')/u', $text, $b)) {
            throw new \RuntimeException('ERSTE kreditní karta: chybí počáteční nebo konečný zůstatek.');
        }
        $prev = CreditCardPdfText::money($a[1], '.');
        $curr = CreditCardPdfText::money($b[1], '.');

        $transactions = $this->transactions($text, $currency);
        CreditCardPdfText::assertBalanced('ERSTE', $transactions, $prev, $curr);

        return [
            'header'       => CreditCardPdfText::header('erste', $account, $bankCode, $to, $number, $prev, $curr, $transactions, $currency, $limit, $from),
            'transactions' => $transactions,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function transactions(string $text, string $currency = 'CZK'): array
    {
        $lines = CreditCardPdfText::lines($text);
        $out = [];
        $card = null;
        $block = null;
        $started = false;
        $flush = function () use (&$block, &$out, $currency): void {
            if ($block !== null) {
                $tx = $this->block($block, $currency);
                if ($tx !== null) {
                    $out[] = $tx;
                }
            }
            $block = null;
        };
        foreach ($lines as $line) {
            if (!$started) {
                if (str_contains($line, 'POČÁTEČNÍ ZŮSTATEK:')) {
                    $started = true;
                }
                continue;
            }
            if (str_starts_with($line, 'KONEČNÝ ZŮSTATEK:')) {
                break;
            }
            if (preg_match('/^KARTA ČÍSLO\s+(.+)$/u', $line, $m)) {
                $flush();
                $card = CardNumberMask::last4FromText($m[1]);
                continue;
            }
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s+(.+)$/u', $line, $m)) {
                $flush();
                $block = [
                    'posted' => CreditCardPdfText::date($m[1], $m[2], $m[3]),
                    'text'   => trim($m[4]),
                    'card'   => $card,
                    'tx_date' => null,
                    'foreign' => null,
                    'amount' => null,
                    'tx_last4' => null,
                ];
                if (preg_match('/^(.*?)\s+(' . self::M . ')$/u', $block['text'], $a)) {
                    $block['amount'] = CreditCardPdfText::money($a[2], '.');
                    $block['text'] = trim($a[1]);
                }
                continue;
            }
            if ($block === null) {
                continue;
            }
            if (preg_match('/[Xx*]{4,}(\d{4})\s*d\.tran\.\s*(\d{2})\.(\d{2})\.(\d{4})/u', $line, $m)) {
                $block['tx_last4'] = $m[1];
                $block['tx_date'] = CreditCardPdfText::date($m[2], $m[3], $m[4]);
                continue;
            }
            if (preg_match('/^(' . self::M . ')\s*([A-Z]{3})\s+(' . self::M . ')$/u', $line, $m)) {
                $block['foreign'] = trim($m[1]) . ' ' . $m[2];
                $block['amount'] = CreditCardPdfText::money($m[3], '.');
                continue;
            }
            if (preg_match('/^(' . self::M . ')\s*([A-Z]{3})$/u', $line, $m)) {
                $block['foreign'] = trim($m[1]) . ' ' . $m[2];
                continue;
            }
            if (preg_match('/^' . self::M . '$/u', $line)) {
                $block['amount'] = CreditCardPdfText::money($line, '.');
            }
        }
        $flush();
        return $out;
    }

    /** @param array{posted:?string, text:string, card:?string, tx_date:?string, foreign:?string, amount:?float, tx_last4:?string} $block */
    private function block(array $block, string $currency): ?array
    {
        if ($block['posted'] === null || $block['amount'] === null || abs($block['amount']) < 0.005) {
            return null;
        }
        $text = $block['text'];
        $account = null;
        $bank = null;
        if (preg_match('/((?:\d{1,6}-)?\d{2,10})\s*\/\s*(\d{4})/u', $text, $m)) {
            $account = $m[1];
            $bank = $m[2];
            $text = trim(str_replace($m[0], '', $text));
        }
        // „Platba kartou Prague          Obchodník" - typ + místo, obchodník za širokou mezerou.
        $parts = preg_split('/\s{2,}|\t/u', $text) ?: [$text];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
        $head = $parts[0] ?? $text;
        $merchant = count($parts) >= 2 ? $parts[count($parts) - 1] : null;
        $type = $head;
        $place = null;
        foreach (['Platba kartou', 'Výběr z bankomatu', 'Výběr hotovosti', 'Vrácení platby', 'Vratka'] as $known) {
            if (str_starts_with($head, $known)) {
                $type = $known;
                $place = trim(substr($head, strlen($known))) ?: null;
                break;
            }
        }
        $description = CreditCardPdfText::description($type, $block['tx_date'], [$block['foreign'], $place, $account === null ? null : $account . '/' . $bank]);
        return CreditCardPdfText::transaction(
            $block['posted'],
            $block['amount'],
            $currency,
            $description,
            $merchant,
            $block['tx_last4'] ?? $block['card'],
            $account,
            $bank,
        );
    }
}
