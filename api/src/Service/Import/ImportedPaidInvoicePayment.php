<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use PDO;

/**
 * Platba k vydanému dokladu, který zdrojový systém (Fakturoid, iDoklad) převádí jako
 * uhrazený. Stav `paid` bez řádku v `invoice_payments` porušuje invariant, který
 * zavedla migrace 0108: spárovaný pohyb z výpisu pak nejde zaúčtovat, protože
 * {@see \MyInvoice\Service\Accounting\Bank\LegacyBankPaymentReconciler} nemá platbu,
 * kterou by na pohyb navázal, a návrh skončí v `already_paid_verify`.
 *
 * Zapisuje totéž co backfill 0108 i opravná migrace 1941: jednu platbu `legacy` na
 * celou částku k úhradě ke dni úhrady. Doklad krytý zálohou (amount_to_pay <= 0)
 * platbu nedostane, neproběhla. Volá se až po přepočtu dokladu.
 */
final class ImportedPaidInvoicePayment
{
    public static function record(PDO $pdo, int $invoiceId): void
    {
        $pdo->prepare(
            "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source)
             SELECT i.supplier_id, i.id, COALESCE(i.paid_at, i.issue_date), i.amount_to_pay,
                    COALESCE(cur.code, 'CZK'), 'legacy'
               FROM invoices i
               LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ?
                AND i.status = 'paid'
                AND i.invoice_type IN ('invoice', 'proforma')
                AND i.amount_to_pay > 0
                AND NOT EXISTS (SELECT 1 FROM invoice_payments p WHERE p.invoice_id = i.id)"
        )->execute([$invoiceId]);

        $pdo->prepare(
            'UPDATE invoices i
                SET i.paid_total = (SELECT COALESCE(SUM(p.amount), 0)
                                      FROM invoice_payments p WHERE p.invoice_id = i.id)
              WHERE i.id = ?'
        )->execute([$invoiceId]);
    }
}
