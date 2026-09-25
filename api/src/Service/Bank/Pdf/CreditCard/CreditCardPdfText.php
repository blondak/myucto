<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Pdf\CreditCard;

use MyInvoice\Service\Bank\Card\CardNumberMask;
use MyInvoice\Service\Bank\CreditCard\CreditCardTransactionKind;

/**
 * Společné kroky parserů výpisů kreditních karet: čtení částek v zápisech jednotlivých
 * bank, sestavení popisu pohybu, tvar hlavičky a transakce a kontrolní součet.
 *
 * Tvar výstupu je stejný jako u parserů běžných účtů ({@see \MyInvoice\Service\Bank\Pdf\BankStatementPdfParserInterface}),
 * hlavička navíc nese `account_kind = credit_card`, vydavatele, kód banky a úvěrový limit.
 */
final class CreditCardPdfText
{
    /** Částka „1 234,56" (KB, ČSOB): mezera nebo NBSP jako oddělovač tisíců, desetinná čárka. */
    public const MONEY_COMMA = '[+-]?\d{1,3}(?:[ \x{00A0}]\d{3})*,\d{2}';

    /** Částka „1 234.56" (ERSTE): mezera jako oddělovač tisíců, desetinná tečka. */
    public const MONEY_DOT = '[+-]?\d{1,3}(?:[ \x{00A0}]\d{3})*\.\d{2}';

    /** Částka „1.234,56" (RB): tečka jako oddělovač tisíců, desetinná čárka. */
    public const MONEY_RB = '[+-]?\d{1,3}(?:\.\d{3})*,\d{2}';

    /** „-1 234,56" / „+1.234,56" / „1 234.56" → float podle zápisu banky. */
    public static function money(string $raw, string $decimal = ','): float
    {
        $s = str_replace(["\u{00A0}", ' ', "\t"], '', trim($raw));
        if ($decimal === ',') {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
        return round((float) $s, 2);
    }

    /** @return list<string> řádky textu bez prázdných, NBSP převedené na mezeru */
    public static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $raw) {
            $line = trim((string) preg_replace('/\x{00A0}/u', ' ', (string) $raw));
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    /** „dd.mm.yyyy" / „d. m. yyyy" → „yyyy-mm-dd", neplatné datum → null. */
    public static function date(string $day, string $month, string $year): ?string
    {
        $d = (int) $day;
        $m = (int) $month;
        $y = (int) $year;
        if (!checkdate($m, $d, $y)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /**
     * Popis pohybu: typ transakce z výpisu VŽDY jako první část (podle něj
     * {@see CreditCardTransactionKind} určuje druh), pak datum transakce a původní částka.
     *
     * @param list<?string> $details
     */
    public static function description(string $type, ?string $transactionDate, array $details = []): string
    {
        $parts = [trim($type) !== '' ? trim($type) : 'Pohyb'];
        if ($transactionDate !== null) {
            $parts[] = 'd.tran. ' . date('d.m.Y', (int) strtotime($transactionDate));
        }
        foreach ($details as $d) {
            $d = trim((string) $d);
            if ($d !== '' && !in_array($d, $parts, true)) {
                $parts[] = $d;
            }
        }
        return mb_substr(implode(' | ', $parts), 0, 255);
    }

    /**
     * Řádek transakce ve tvaru GpcParser. Koncovka karty jen u karetních operací -
     * poplatek nebo úrok v sekci karty se jako platba kartou párovat nesmí.
     *
     * @return array<string,mixed>
     */
    public static function transaction(
        string $postedAt,
        float $amount,
        string $currency,
        string $description,
        ?string $counterpartyName = null,
        ?string $cardLast4 = null,
        ?string $counterpartyAccount = null,
        ?string $counterpartyBank = null,
        ?string $bankRef = null,
        ?string $variableSymbol = null,
    ): array {
        $kind = CreditCardTransactionKind::classify($description, $amount);
        $last4 = in_array($kind, CreditCardTransactionKind::CARD_KINDS, true) && CardNumberMask::isValidLast4($cardLast4)
            ? $cardLast4
            : null;
        $name = $counterpartyName !== null ? trim($counterpartyName) : '';
        return [
            'posted_at'            => $postedAt,
            'amount'               => round($amount, 2),
            'currency'             => $currency,
            'variable_symbol'      => $variableSymbol !== null && $variableSymbol !== '' ? (ltrim($variableSymbol, '0') ?: null) : null,
            'constant_symbol'      => null,
            'specific_symbol'      => null,
            'counterparty_account' => $counterpartyAccount,
            'counterparty_bank'    => $counterpartyBank,
            'counterparty_name'    => $name !== '' ? mb_substr($name, 0, 190) : null,
            'description'          => $description,
            'bank_ref'             => $bankRef !== null && trim($bankRef) !== '' ? mb_substr(trim($bankRef), 0, 40) : null,
            'card_last4'           => $last4,
            'credit_card_kind'     => $kind,
        ];
    }

    /**
     * Kontrola, že pohyby tvoří celý výpis: součet = konečný − počáteční zůstatek na haléř.
     * Nesedí-li, výpis se odmítne - částečná finanční data se nikdy neuloží.
     *
     * @param list<array<string,mixed>> $transactions
     */
    public static function assertBalanced(string $bank, array $transactions, float $prev, float $curr): void
    {
        $sum = 0.0;
        foreach ($transactions as $tx) {
            $sum += (float) $tx['amount'];
        }
        $expected = round($curr - $prev, 2);
        if (abs(round($sum, 2) - $expected) > 0.005) {
            throw new \RuntimeException(sprintf(
                '%s kreditní karta: součet pohybů (%.2f) nesedí na změnu zůstatku výpisu (%.2f). Import zamítnut.',
                $bank,
                $sum,
                $expected,
            ));
        }
    }

    /**
     * Hlavička ve tvaru GpcParser + údaje úvěrového účtu.
     *
     * @param list<array<string,mixed>> $transactions
     * @return array<string,mixed>
     */
    public static function header(
        string $issuer,
        string $accountNumber,
        ?string $bankCode,
        string $statementDate,
        string $statementNumber,
        float $prev,
        float $curr,
        array $transactions,
        string $currency,
        ?float $creditLimit,
        ?string $periodFrom,
    ): array {
        $credit = 0.0;
        $debit = 0.0;
        foreach ($transactions as $tx) {
            $a = (float) $tx['amount'];
            if ($a > 0) {
                $credit += $a;
            } else {
                $debit += -$a;
            }
        }
        return [
            'account_number'   => $accountNumber,
            'statement_date'   => $statementDate,
            'statement_number' => $statementNumber,
            'prev_balance'     => round($prev, 2),
            'curr_balance'     => round($curr, 2),
            'debit_total'      => round($debit, 2),
            'credit_total'     => round($credit, 2),
            'account_kind'     => 'credit_card',
            'issuer'           => $issuer,
            'bank_code'        => $bankCode,
            'currency'         => $currency,
            'credit_limit'     => $creditLimit,
            'period_from'      => $periodFrom,
            'period_to'        => $statementDate,
        ];
    }
}
