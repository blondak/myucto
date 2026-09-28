<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration\Shared;

use MyInvoice\Service\Migration\Shared\MigratedDocumentWriter;
use MyInvoice\Service\Migration\Shared\MigratedIssuedDocument;
use MyInvoice\Service\Migration\Shared\MigratedPaymentWriter;
use MyInvoice\Service\Migration\Shared\MigratedPurchaseDocument;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class MigratedPaymentWriterSettlementTest extends SharedMigrationDbTestCase
{
    public function testPartialCreditNoteRefundDoesNotSetPaidStateForIssuedOrPurchase(): void
    {
        $supplierId = $this->supplier();
        $supplier = $this->row('SELECT country_id, default_currency_id, default_vat_rate_id
            FROM supplier WHERE id=?', [$supplierId]);
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO clients
            (supplier_id, company_name, street, city, zip, country_id, main_email,
             currency_default_id, vat_rate_default_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $supplierId, 'Synthetic partner', 'Testovací 1', 'Vzorov', '10000', $supplier['country_id'],
            'partner@example.invalid', $supplier['default_currency_id'], $supplier['default_vat_rate_id'],
        ]);
        $clientId = (int) $pdo->lastInsertId();
        $documents = new MigratedDocumentWriter($this->db);
        $issuedId = $documents->insertIssued(new MigratedIssuedDocument(
            $supplierId, 'credit_note', $clientId, 'SYN-CREDIT-1', '2025-03-01', '2025-03-01',
            '2025-03-14', (int) $supplier['default_currency_id'], null, false, false,
            null, null, '{}', -100.0, -21.0, -121.0, 0.0, 'sent', $this->userId,
        ));
        $purchaseId = $documents->insertPurchase(new MigratedPurchaseDocument(
            $supplierId, $clientId, true, 'SYN-PURCHASE-1', 'SYN-VENDOR-1', 'credit_note',
            '2025-03-01', '2025-03-01', '2025-03-14', '2025-03-01', 'import',
            (int) $supplier['default_currency_id'], null, false, false, '{}',
            -100.0, -21.0, -121.0, 0.0, 'booked', 'none', null, null, $this->userId,
        ));
        $pdo->prepare('INSERT INTO invoice_payments
            (supplier_id, invoice_id, paid_on, amount, currency, source, created_by)
            VALUES (?, ?, "2025-03-02", 50, "CZK", "manual", ?)')
            ->execute([$supplierId, $issuedId, $this->userId]);
        $payments = new MigratedPaymentWriter($this->db);
        $registerId = $payments->cashRegister($supplierId, 'Synthetic register');
        $cashId = $payments->insertCash($supplierId, $registerId, 'SYN-CASH-1', '2025-03-02',
            'Partial refund', 50.0, false, $this->userId);
        $payments->attach($supplierId, $this->userId, 'purchase', 'cash', $purchaseId, $cashId, 50.0);

        $payments->refreshBalances($supplierId, [$issuedId], [$purchaseId], []);

        $issued = $this->row('SELECT status, paid_total, paid_at FROM invoices WHERE id=? AND supplier_id=?',
            [$issuedId, $supplierId]);
        self::assertSame('sent', $issued['status']);
        self::assertEquals(50.0, (float) $issued['paid_total']);
        self::assertNull($issued['paid_at']);
        $purchase = $this->row('SELECT status, paid_amount_invoice_ccy, paid_at
            FROM purchase_invoices WHERE id=? AND supplier_id=?', [$purchaseId, $supplierId]);
        self::assertSame('booked', $purchase['status']);
        self::assertEquals(50.0, (float) $purchase['paid_amount_invoice_ccy']);
        self::assertNull($purchase['paid_at']);

        $pdo->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ? AND supplier_id = ?")
            ->execute([$issuedId, $supplierId]);
        $pdo->prepare("UPDATE purchase_invoices SET status = 'cancelled' WHERE id = ? AND supplier_id = ?")
            ->execute([$purchaseId, $supplierId]);
        $pdo->prepare('UPDATE invoice_payments SET amount = 121 WHERE invoice_id = ? AND supplier_id = ?')
            ->execute([$issuedId, $supplierId]);
        $pdo->prepare('UPDATE cash_documents SET total_amount = 121 WHERE id = ? AND supplier_id = ?')
            ->execute([$cashId, $supplierId]);

        $payments->refreshBalances($supplierId, [$issuedId], [$purchaseId], []);

        self::assertSame('cancelled', $this->row('SELECT status FROM invoices WHERE id=? AND supplier_id=?',
            [$issuedId, $supplierId])['status']);
        self::assertSame('cancelled', $this->row('SELECT status FROM purchase_invoices WHERE id=? AND supplier_id=?',
            [$purchaseId, $supplierId])['status']);

        $roundedId = $documents->insertPurchase(new MigratedPurchaseDocument(
            $supplierId, $clientId, true, 'SYN-ROUNDED', 'SYN-VENDOR-ROUNDED', 'invoice',
            '2025-03-01', '2025-03-01', '2025-03-14', '2025-03-01', 'import',
            (int) $supplier['default_currency_id'], null, false, false, '{}',
            100.0, 21.2, 121.2, -0.2, 'booked', 'none', null, null, $this->userId,
        ));
        $roundedCash = $payments->insertCash($supplierId, $registerId, 'SYN-CASH-ROUNDED',
            '2025-03-02', 'Rounded payment', 121.0, false, $this->userId);
        $payments->attach($supplierId, $this->userId, 'purchase', 'cash', $roundedId, $roundedCash, 121.0);
        $payments->refreshBalances($supplierId, [], [$roundedId], []);
        self::assertSame('paid', $this->row('SELECT status FROM purchase_invoices WHERE id=? AND supplier_id=?',
            [$roundedId, $supplierId])['status']);

        $underpaidId = $documents->insertPurchase(new MigratedPurchaseDocument(
            $supplierId, $clientId, true, 'SYN-ROUND-UP', 'SYN-VENDOR-ROUND-UP', 'invoice',
            '2025-03-01', '2025-03-01', '2025-03-14', '2025-03-01', 'import',
            (int) $supplier['default_currency_id'], null, false, false, '{}',
            100.0, 21.2, 121.2, 0.2, 'booked', 'none', null, null, $this->userId,
        ));
        $underpaidCash = $payments->insertCash($supplierId, $registerId, 'SYN-CASH-ROUND-UP',
            '2025-03-02', 'Underpaid rounded invoice', 121.2, false, $this->userId);
        $payments->attach($supplierId, $this->userId, 'purchase', 'cash', $underpaidId, $underpaidCash, 121.2);
        $payments->refreshBalances($supplierId, [], [$underpaidId], []);
        self::assertSame('booked', $this->row('SELECT status FROM purchase_invoices WHERE id=? AND supplier_id=?',
            [$underpaidId, $supplierId])['status']);
    }

    public function testCashLinkRejectsPartialAllocation(): void
    {
        $supplierId = $this->supplier();
        $pdo = $this->db->pdo();
        $supplier = $this->row('SELECT country_id, default_currency_id, default_vat_rate_id
            FROM supplier WHERE id=?', [$supplierId]);
        $pdo->prepare('INSERT INTO clients
            (supplier_id, company_name, street, city, zip, country_id, main_email,
             currency_default_id, vat_rate_default_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $supplierId, 'Synthetic partner', 'Testovací 1', 'Vzorov', '10000', $supplier['country_id'],
            'partner@example.invalid', $supplier['default_currency_id'], $supplier['default_vat_rate_id'],
        ]);
        $purchaseId = (new MigratedDocumentWriter($this->db))->insertPurchase(new MigratedPurchaseDocument(
            $supplierId, (int) $pdo->lastInsertId(), true, 'SYN-PARTIAL', 'SYN-VENDOR-PARTIAL', 'invoice',
            '2025-03-01', '2025-03-01', '2025-03-14', '2025-03-01', 'import',
            (int) $supplier['default_currency_id'], null, false, false, '{}',
            100.0, 21.0, 121.0, 0.0, 'booked', 'none', null, null, $this->userId,
        ));
        $payments = new MigratedPaymentWriter($this->db);
        $registerId = $payments->cashRegister($supplierId, 'Synthetic register');
        $cashId = $payments->insertCash($supplierId, $registerId, 'SYN-CASH-PARTIAL',
            '2025-03-02', 'Partial allocation', 200.0, false, $this->userId);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cash_payment_ambiguous');
        $payments->attach($supplierId, $this->userId, 'purchase', 'cash', $purchaseId, $cashId, 60.0);
    }
}
