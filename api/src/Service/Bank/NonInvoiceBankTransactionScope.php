<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * Bankovní pohyb, u kterého se spárování s fakturou nečeká, protože ho uzavírá něco jiného:
 *
 *   - **vlastní převod** — druhá noha leží na jiném vlastním účtu; pozná se podle návrhu
 *     zaúčtování `source = 'transfer'` ({@see \MyInvoice\Service\Accounting\Bank\TransferPairService}),
 *   - **mzdová platba** — visí v `payroll_payment_matches`, stejně jako `payroll_matched`
 *     v {@see \MyInvoice\Service\Payroll\Payment\PayrollBankEvidenceGuard::postingInfo()}.
 *
 * `match_status` jim zůstává `unmatched`, protože ten popisuje jen párování s fakturou.
 * Kdo se ptá „kolik pohybů ještě čeká na spárování", musí je proto vynechat tady —
 * jinak svítí jako nevyřízené v počítadle výpisu i v akcích na úvodní stránce.
 */
final class NonInvoiceBankTransactionScope
{
    public static function ownTransferSql(int|string $supplierId, string $transactionIdSql): string
    {
        self::validate($supplierId, $transactionIdSql);
        return "EXISTS (
            SELECT 1 FROM bank_posting_suggestions non_invoice_transfer
             WHERE non_invoice_transfer.supplier_id = $supplierId
               AND non_invoice_transfer.bank_transaction_id = $transactionIdSql
               AND non_invoice_transfer.source = 'transfer'
               AND non_invoice_transfer.status IN ('pending', 'approved', 'auto_posted'))";
    }

    public static function sql(int|string $supplierId, string $transactionIdSql): string
    {
        self::validate($supplierId, $transactionIdSql);
        return '(' . self::ownTransferSql($supplierId, $transactionIdSql) . " OR EXISTS (
            SELECT 1 FROM payroll_payment_matches non_invoice_payroll
             WHERE non_invoice_payroll.supplier_id = $supplierId
               AND non_invoice_payroll.bank_transaction_id = $transactionIdSql))";
    }

    private static function validate(int|string $supplierId, string $transactionIdSql): void
    {
        $reference = '/^[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*$/D';
        if ((is_int($supplierId) ? $supplierId <= 0 : preg_match($reference, $supplierId) !== 1)
            || preg_match($reference, $transactionIdSql) !== 1
        ) {
            throw new \InvalidArgumentException('Neplatný alias bankovního pohybu.');
        }
    }
}
