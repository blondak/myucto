<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Jediný zdroj pravdy pro „doklad k vyplacení": vystavený doklad, u kterého
 * dlužíme peníze zákazníkovi (amount_to_pay < 0). Patří sem dobropis a při
 * zapnutém `supplier.allow_refund_invoices` i faktura, jejíž odpočty (vrácené
 * obaly, přeplatek záloh) převážily plnění.
 *
 * Vrácení peněz se neeviduje v `invoice_payments` (ta nese jen kladné úhrady),
 * ale stavem dokladu, stejně jako u dobropisu: status `paid` + `paid_at`.
 *
 * Faktura s `parent_invoice_id` (finál k proformě s přeplatkem zálohy) sem nepatří:
 * označí se jako zaplacená už při vystavení a její uhrazenost počítá poměr záloh.
 * U dobropisu `parent_invoice_id` jen odkazuje na opravovanou fakturu.
 */
final class RefundDocument
{
    public const TYPES = ['invoice', 'credit_note'];
    public const OPEN_STATUSES = ['issued', 'sent', 'reminded'];

    public static function isRefundDocument(array $invoice): bool
    {
        $type = (string) ($invoice['invoice_type'] ?? '');

        return in_array($type, self::TYPES, true)
            && round((float) ($invoice['amount_to_pay'] ?? 0), 2) < 0
            && ($type === 'credit_note' || (int) ($invoice['parent_invoice_id'] ?? 0) <= 0);
    }

    public static function isOpenRefund(array $invoice): bool
    {
        return self::isRefundDocument($invoice)
            && in_array((string) ($invoice['status'] ?? ''), self::OPEN_STATUSES, true);
    }

    /** Částka k vyplacení (kladná), 0 pro doklad, který k vyplacení není. */
    public static function refundAmount(array $invoice): float
    {
        return self::isRefundDocument($invoice) ? round(-(float) $invoice['amount_to_pay'], 2) : 0.0;
    }

    /**
     * SQL protějšek {@see isRefundDocument()}.
     *
     * @param string $alias alias tabulky `invoices`; prázdný řetězec = bez aliasu
     */
    public static function refundDocumentSql(string $alias = 'i'): string
    {
        $p = $alias === '' ? '' : $alias . '.';

        return "({$p}invoice_type IN ('invoice','credit_note') AND {$p}amount_to_pay < 0"
            . " AND ({$p}invoice_type = 'credit_note' OR {$p}parent_invoice_id IS NULL))";
    }

    /** SQL protějšek {@see isOpenRefund()}. */
    public static function openRefundSql(string $alias = 'i'): string
    {
        $p = $alias === '' ? '' : $alias . '.';

        return '(' . self::refundDocumentSql($alias) . " AND {$p}status IN ('issued','sent','reminded'))";
    }

    public static function enabledForSupplier(\PDO $pdo, int $supplierId): bool
    {
        if ($supplierId <= 0) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT allow_refund_invoices FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);

        return (bool) $stmt->fetchColumn();
    }
}
