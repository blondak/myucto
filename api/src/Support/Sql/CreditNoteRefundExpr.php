<?php

declare(strict_types=1);

namespace MyInvoice\Support\Sql;

/**
 * Jediný zdroj pravdy pro predikát „vydaný dobropis už byl zákazníkovi PROPLACEN".
 *
 * Dobropis se nikdy neobjeví v `invoice_payments` — `PAYABLE_TYPES` ho z evidence
 * úhrad vylučuje, protože peníze tečou opačným směrem. Vrácení peněz se proto
 * eviduje výhradně stavem dokladu: {@see \MyInvoice\Service\Invoice\InvoicePaymentService::markCreditNoteRefunded()}
 * nastaví `status='paid'` + `paid_at` (volá ji ruční „označit vrácené" i GoPay
 * vyúčtování, které k dobropisu spáruje pohyb `storno`).
 *
 * Saldokonto dovozuje uhrazenost vydaných dokladů z `invoice_payments`, takže bez
 * tohohle predikátu zůstal proplacený dobropis navždy otevřenou zápornou položkou
 * (a konfrontace se zůstatkem hlavní knihy vykázala rozdíl v jeho výši, přestože
 * deník má obě strany — předpis i vratku — vyrovnané).
 *
 * NEproplacený dobropis otevřenou položkou ZŮSTÁVÁ: v saldu partnera se svým
 * záporným znaménkem správně odečítá od neuhrazených faktur, dokud se buď
 * nezapočte, nebo nevrátí v penězích.
 */
final class CreditNoteRefundExpr
{
    /**
     * SQL predikát „dobropis je k rozvahovému dni proplacený". Obsahuje JEDEN
     * placeholder pro `asOf` (v `SaldoRepository` jsou všechny placeholdery asOf,
     * takže se parametry nedají prohodit).
     *
     * @param string $alias alias tabulky `invoices`; prázdný řetězec = bez aliasu
     */
    public static function refundedAsOfSql(string $alias = 'i'): string
    {
        $p = $alias === '' ? '' : $alias . '.';

        return "({$p}invoice_type = 'credit_note' AND {$p}status = 'paid'"
            . " AND {$p}paid_at IS NOT NULL AND DATE({$p}paid_at) <= ?)";
    }

    /** PHP protějšek {@see refundedAsOfSql()} nad řádkem s `invoice_type`/`status`/`paid_at`. */
    public static function isRefundedAsOf(string $invoiceType, string $status, ?string $paidAt, string $asOf): bool
    {
        return $invoiceType === 'credit_note'
            && $status === 'paid'
            && $paidAt !== null
            && substr($paidAt, 0, 10) <= $asOf;
    }
}
