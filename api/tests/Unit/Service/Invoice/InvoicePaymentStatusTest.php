<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Odvozený platební stav (#89) — InvoicePaymentService::paymentStatus() je čistá
 * funkce nad (status, amount_to_pay, paid_total) s tolerancí 0,05 Kč (DPH
 * zaokrouhlení, zrcadlí StatementMatcher::EXACT_MATCH_TOLERANCE).
 *
 * + InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue pro typ 'tax_document'
 * (daňový doklad k přijaté platbě je z podstaty uhrazený už při vystavení).
 */
final class InvoicePaymentStatusTest extends TestCase
{
    /** @param array<string,mixed> $invoice */
    private static function ps(array $invoice): ?string
    {
        return InvoicePaymentService::paymentStatus($invoice);
    }

    public function testDraftAndCancelledHaveNoPaymentStatus(): void
    {
        self::assertNull(self::ps(['status' => 'draft', 'amount_to_pay' => 100, 'paid_total' => 0]));
        self::assertNull(self::ps(['status' => 'cancelled', 'amount_to_pay' => 100, 'paid_total' => 100]));
    }

    public function testUnpaidWithoutPayments(): void
    {
        self::assertSame('unpaid', self::ps(['status' => 'issued', 'amount_to_pay' => 1000.0, 'paid_total' => 0.0]));
        self::assertSame('unpaid', self::ps(['status' => 'reminded', 'amount_to_pay' => 1000.0, 'paid_total' => 0.0]));
    }

    public function testPartiallyPaidKeepsLifecycleButReportsPartial(): void
    {
        self::assertSame('partially_paid', self::ps(['status' => 'issued', 'amount_to_pay' => 1000.0, 'paid_total' => 400.0]));
        self::assertSame('partially_paid', self::ps(['status' => 'sent', 'amount_to_pay' => 1000.0, 'paid_total' => 999.90]));
    }

    public function testCoveredWithinToleranceIsPaid(): void
    {
        // DPH zaokrouhlení ±0,05 — banka pošle 999,97 na 1000,00.
        self::assertSame('paid', self::ps(['status' => 'issued', 'amount_to_pay' => 1000.0, 'paid_total' => 999.97]));
        self::assertSame('paid', self::ps(['status' => 'paid', 'amount_to_pay' => 1000.0, 'paid_total' => 1000.0]));
    }

    public function testLifecyclePaidWithoutPaymentsIsPaid(): void
    {
        // Finální doklad krytý zálohou: amount_to_pay = 0, žádné platby, auto-paid.
        self::assertSame('paid', self::ps(['status' => 'paid', 'amount_to_pay' => 0.0, 'paid_total' => 0.0]));
    }

    public function testOverpaidBeyondTolerance(): void
    {
        self::assertSame('overpaid', self::ps(['status' => 'paid', 'amount_to_pay' => 1000.0, 'paid_total' => 1100.0]));
        // Stav issued s přeplatkem (ještě nepřeklopeno) — pořád overpaid.
        self::assertSame('overpaid', self::ps(['status' => 'issued', 'amount_to_pay' => 1000.0, 'paid_total' => 1000.10]));
        // Drobný přeplatek v toleranci NENÍ overpaid.
        self::assertSame('paid', self::ps(['status' => 'paid', 'amount_to_pay' => 1000.0, 'paid_total' => 1000.04]));
    }

    // ── InvoiceAmountPolicy pro tax_document ─────────────────────────────────

    public function testTaxDocumentAutoMarksPaidOnIssue(): void
    {
        self::assertTrue(InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue([
            'invoice_type' => 'tax_document', 'parent_invoice_id' => 42, 'amount_to_pay' => 0.0,
        ]), 'Daňový doklad k přijaté platbě (advance = brutto platby) se vystavením rovnou platí.');
    }

    public function testTaxDocumentWithoutParentDoesNotAutoPay(): void
    {
        self::assertFalse(InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue([
            'invoice_type' => 'tax_document', 'parent_invoice_id' => null, 'amount_to_pay' => 0.0,
        ]));
    }

    public function testCreditNoteStillNeverAutoPays(): void
    {
        self::assertFalse(InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue([
            'invoice_type' => 'credit_note', 'parent_invoice_id' => 42, 'amount_to_pay' => -500.0,
        ]));
    }

    public function testFinalInvoiceBehaviourUnchanged(): void
    {
        self::assertTrue(InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue([
            'invoice_type' => 'invoice', 'parent_invoice_id' => 42, 'amount_to_pay' => 0.0,
        ]));
        self::assertFalse(InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue([
            'invoice_type' => 'invoice', 'parent_invoice_id' => 42, 'amount_to_pay' => 100.0,
        ]), 'Finál s nedoplatkem (částečná záloha) zůstává nezaplacený.');
    }
}
