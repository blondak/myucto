<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Pdf\CreditCard;

use MyInvoice\Service\Bank\Card\CardNumberMask;
use MyInvoice\Service\Bank\Pdf\BankStatementPdfParserInterface;

/**
 * Výpis z úvěrového účtu ke kreditní kartě Komerční banky („VÝPIS Z ÚČTU KE KREDITNÍ KARTĚ").
 *
 * Layout (text ze Smalot): hlavička „k účtu: 35-…/0100", „Číslo výpisu:", „Datum výpisu:",
 * „Za období: dd.mm. - dd.mm.yyyy"; souhrn „Vyčerpáno k datu minulého výpisu / Čerpáno /
 * Splaceno / Vyčerpáno k datu výpisu" (dluh jako KLADNÉ číslo - parser ho otočí na záporný
 * zůstatek, ať sedí znaménko s ostatními bankami a s 231); tabulka „Přehled transakcí".
 *
 * Pohyb v tabulce: datum zaúčtování, (datum transakce), typ, „zúčt. částka", „orig. částka",
 * „kurz", identifikace, karta, obchodník, místo a nakonec částky. U splátky a úroku stojí na
 * konci čtyři částky v pořadí Celkem, Jistina, Úrok, Zaúčtováno - do zůstatku jde vždy
 * POSLEDNÍ (Zaúčtováno). Pohyb se zaúčtovanou nulou (splnění podmínek zvýhodnění úroků)
 * zůstatek nemění a nezakládá se.
 *
 * Mezi stránkami se opakuje hlavička výpisu (i adresa pobočky a klienta) - přeskočí se vše
 * od značky konce stránky po řádek „Zaúčtováno" záhlaví tabulky.
 */
final class KbCreditCardStatementPdfParser implements BankStatementPdfParserInterface
{
    private const M = CreditCardPdfText::MONEY_COMMA;

    public function key(): string
    {
        return 'kb_credit_card';
    }

    public function supports(string $text): bool
    {
        return str_contains($text, 'VÝPIS Z ÚČTU KE KREDITNÍ KARTĚ')
            && (str_contains($text, 'Komerční banka') || str_contains($text, 'KOMBCZPP'));
    }

    public function parse(string $pdfBytes, string $text): array
    {
        if (!preg_match('/k účtu:\s*([\d-]+)\/(\d{4})/u', $text, $m)) {
            throw new \RuntimeException('KB kreditní karta: chybí číslo účtu („k účtu:").');
        }
        $account = $m[1];
        $bankCode = $m[2];

        if (!preg_match('/Za období:\s*(\d{1,2})\.\s*(\d{1,2})\.\s*-\s*(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/u', $text, $p)) {
            throw new \RuntimeException('KB kreditní karta: chybí období výpisu („Za období:").');
        }
        $to = CreditCardPdfText::date($p[3], $p[4], $p[5]);
        $fromYear = (int) $p[2] > (int) $p[4] ? (int) $p[5] - 1 : (int) $p[5];
        $from = CreditCardPdfText::date($p[1], $p[2], (string) $fromYear);
        if ($to === null) {
            throw new \RuntimeException('KB kreditní karta: neplatné období výpisu.');
        }
        if (preg_match('/Datum výpisu:\s*(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $text, $d)) {
            $to = CreditCardPdfText::date($d[1], $d[2], $d[3]) ?? $to;
        }
        $number = preg_match('/Číslo výpisu:\s*(\d+)/u', $text, $n) ? $n[1] : '';
        $currency = preg_match('/měna:\s*([A-Z]{3})/u', $text, $c) ? $c[1] : 'CZK';
        $limit = preg_match('/Úvěrový limit[^\d]*?(' . self::M . ')\s*Kč/u', $text, $l)
            ? CreditCardPdfText::money($l[1]) : null;

        if (!preg_match('/Vyčerpáno k datu minulého výpisu[^\n]*\n\s*(' . self::M . ')\s+(' . self::M . ')\s+(' . self::M . ')\s+(' . self::M . ')/u', $text, $s)) {
            throw new \RuntimeException('KB kreditní karta: chybí přehled čerpání (vyčerpáno k datu minulého výpisu).');
        }
        $prevDebt = CreditCardPdfText::money($s[1]);
        $currDebt = preg_match('/Celkem čerpání a poplatky k datu výpisu\s*(' . self::M . ')/u', $text, $t)
            ? CreditCardPdfText::money($t[1])
            : CreditCardPdfText::money($s[4]);

        $transactions = $this->transactions($text, $currency);
        $prev = -$prevDebt;
        $curr = -$currDebt;
        CreditCardPdfText::assertBalanced('KB', $transactions, $prev, $curr);
        if (preg_match('/^Celkem\s+(' . self::M . ')\s*$/mu', $text, $total)) {
            CreditCardPdfText::assertBalanced('KB', $transactions, 0.0, CreditCardPdfText::money($total[1]));
        }

        return [
            'header'       => CreditCardPdfText::header('kb', $account, $bankCode, $to, $number, $prev, $curr, $transactions, $currency, $limit, $from),
            'transactions' => $transactions,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function transactions(string $text, string $currency = 'CZK'): array
    {
        $lines = CreditCardPdfText::lines($text);
        $start = null;
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, 'Přehled transakcí')) {
                $start = $i + 1;
                break;
            }
        }
        if ($start === null) {
            throw new \RuntimeException('KB kreditní karta: chybí přehled transakcí.');
        }

        $blocks = [];
        $current = null;
        $skipping = true; // záhlaví tabulky až po „Zaúčtováno"
        for ($i = $start, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];
            if ($skipping) {
                if ($line === 'Zaúčtováno') {
                    $skipping = false;
                }
                continue;
            }
            if (preg_match('/^Celkem\s+' . self::M . '$/u', $line)) {
                break;
            }
            if ($this->isPageBreak($line)) {
                $skipping = true;
                continue;
            }
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})(.*)$/u', $line, $m)) {
                $date = CreditCardPdfText::date($m[1], $m[2], $m[3]);
                $rest = trim($m[4]);
                // Druhé holé datum hned za datem zaúčtování je datum transakce, ne nový pohyb.
                if ($current !== null && $rest === '' && $current['tx_date'] === null && $current['lines'] === [] && $date !== null) {
                    $current['tx_date'] = $date;
                    continue;
                }
                if ($current !== null) {
                    $blocks[] = $current;
                }
                $current = ['date' => $date, 'tx_date' => null, 'lines' => $rest !== '' ? [$rest] : []];
                continue;
            }
            if ($current !== null) {
                $current['lines'][] = $line;
            }
        }
        if ($current !== null) {
            $blocks[] = $current;
        }

        $out = [];
        foreach ($blocks as $block) {
            $tx = $this->block($block, $currency);
            if ($tx !== null) {
                $out[] = $tx;
            }
        }
        return $out;
    }

    private function isPageBreak(string $line): bool
    {
        return str_starts_with($line, 'Pokračování na další straně')
            || str_starts_with($line, 'VÝPIS Z ÚČTU KE KREDITNÍ KARTĚ')
            || str_starts_with($line, 'Datum výpisu:')
            || str_starts_with($line, 'Komerční banka');
    }

    /** @param array{date:?string, tx_date:?string, lines:list<string>} $block */
    private function block(array $block, string $currency): ?array
    {
        if ($block['date'] === null) {
            return null;
        }
        $amounts = [];
        $type = null;
        $details = [];
        $refs = [];
        $cardLast4 = null;
        $afterCard = [];
        $texts = [];
        $account = null;
        $bank = null;
        foreach ($block['lines'] as $line) {
            if (preg_match('/^' . self::M . '$/u', $line)) {
                $amounts[] = CreditCardPdfText::money($line);
                continue;
            }
            if (preg_match('/^(orig\.|zúčt\.)\s*částka:\s*(.+)$/u', $line, $m)) {
                $details[] = $m[1] . ' ' . trim($m[2]);
                continue;
            }
            if (str_starts_with($line, 'kurz:')) {
                continue;
            }
            if (preg_match('/^\d{3}-[\d-]{6,}(?:\s+[\d ]+)?$/u', $line)) {
                $refs[] = $line;
                continue;
            }
            if (preg_match('/^((?:\d{1,6}-)?\d{2,10})\/(\d{4})$/u', $line, $m)) {
                $account = $m[1];
                $bank = $m[2];
                continue;
            }
            $last4 = CardNumberMask::last4FromText($line);
            if ($last4 !== null && preg_match('/\*{2}/', $line)) {
                $cardLast4 = $last4;
                continue;
            }
            if ($type === null) {
                $type = $line;
                continue;
            }
            if ($cardLast4 !== null) {
                $afterCard[] = $line;
            } else {
                $texts[] = $line;
            }
        }
        if ($amounts === []) {
            return null;
        }
        $amount = $amounts[count($amounts) - 1];
        if (abs($amount) < 0.005) {
            return null;
        }
        $type = trim((string) $type);
        $merchant = $afterCard[0] ?? null;
        $place = $afterCard[1] ?? null;
        $counterparty = $merchant;
        if ($merchant === null) {
            // Pohyb bez karty (splátka, úrok): pokračování typu a jméno protistrany.
            $name = null;
            foreach ($texts as $t) {
                if (preg_match('/^[A-Z0-9]{6,}\s+\d{1,3}$/u', $t)) {
                    $refs[] = $t;
                    continue;
                }
                if ($account === null && $name === null && mb_strtoupper($t, 'UTF-8') === $t && mb_strlen($t) <= 12) {
                    $type .= ' ' . $t; // „SPLNĚNÍ PODM ZVÝHODNĚNÍ" + „ÚROKŮ"
                    continue;
                }
                $name = $t;
            }
            $counterparty = $name;
        }
        $description = CreditCardPdfText::description($type, $block['tx_date'], array_merge($details, [$place]));
        return CreditCardPdfText::transaction(
            $block['date'],
            $amount,
            $currency,
            $description,
            $counterparty,
            $cardLast4,
            $account,
            $bank,
            $refs[0] ?? null,
        );
    }
}
