<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalForDocumentAction;
use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Co účetní vidí na zálohové faktuře a na konečné faktuře, která ji vyúčtovává. Prázdná
 * sekce Zaúčtování u zálohy a „uhrazeno, ale nezaúčtováno" u konečné faktury vedly
 * k dojmu, že se nic nezaúčtovalo, a k přesunu platby zálohy na konečnou fakturu.
 */
#[Group('integration')]
final class PurchaseAdvanceDetailTest extends BankPostingTestCase
{
    private int $vatRateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vatRateId = (int) ($this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vatRateId === 0) {
            self::markTestSkipped('Chybí vat_rates v DB.');
        }
    }

    public function testAdvancePostingSectionShowsPaymentAndFinalSettlement(): void
    {
        [$advance, $final] = $this->pair('DET-1');
        $unpaid = array_column($this->postingItems($advance), 'relation');
        self::assertSame(['advance_final'], $unpaid,
            'Nezaplacená záloha nemá úhradový zápis (FE ukáže „zaúčtuje se při úhradě"), jen konečnou fakturu.');

        $tx = $this->payAdvance($advance);
        $items = $this->postingItems($advance);
        $relations = array_map(static fn (array $e): string => $e['source_type'] . ':' . $e['relation'], $items);
        sort($relations);
        self::assertSame(['bank:advance_payment', 'purchase_invoice:advance_final'], $relations);
        foreach ($items as $e) {
            if ($e['source_type'] === 'bank') {
                self::assertSame($tx, (int) $e['source_id']);
                $codes = array_column($e['lines'], 'account_code');
                self::assertContains('314', $codes, 'Úhrada zálohy na 314.');
            }
        }

        $own = $this->postingItems($final);
        self::assertCount(1, $own);
        self::assertSame('own', $own[0]['relation']);
    }

    public function testFinalInvoiceFlagsUnpaidAdvanceAndNotFalseMarkPaidUnposted(): void
    {
        [$advance, $final] = $this->pair('DET-2');
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?")
            ->execute([self::YEAR . '-06-20', $final]);
        $repo = $this->container->get(PurchaseInvoiceRepository::class);

        $row = $repo->find($final, $this->supplierId);
        self::assertTrue($row['linked_advance_unpaid'], 'Záloha bez úhrady → zúčtování chybí, upozornit.');

        $this->payAdvance($advance);
        $row = $repo->find($final, $this->supplierId);
        self::assertFalse($row['linked_advance_unpaid']);
        self::assertFalse($row['mark_paid_unposted'],
            'Faktura krytá zaplacenou zálohou není „uhrazeno, ale nezaúčtováno" — 321 vyrovnalo zúčtování 321/314.');
    }

    public function testPaymentOfUnpaidAdvanceCannotBeMatchedToFinalInvoice(): void
    {
        [$advance, $final] = $this->pair('DET-3');
        $tx = $this->transaction($this->statement(), -1210.00, ['posted_at' => self::YEAR . '-06-24']);

        $res = $this->callAction(
            $this->container->get(BankStatementAction::class),
            'manualMatch',
            'POST',
            'admin',
            ['purchase_invoice_id' => $final],
            ['id' => (string) $tx],
        );
        self::assertSame(409, $res['status'], json_encode($res['body']));
        self::assertSame('advance_payment_belongs_to_advance', $res['body']['error']['code'] ?? null);
        self::assertSame($advance, $res['body']['error']['advance_id'] ?? null);
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = {$tx}"
        )->fetchColumn(), 'Nic se nespárovalo.');
    }

    /** @return array{0:int,1:int} záloha, konečná faktura (zaúčtovaná) */
    private function pair(string $suffix): array
    {
        $vendor = $this->client('Dodavatel ' . $suffix);
        $advance = $this->purchaseInvoice('ZPF-' . $suffix, $vendor, 1210.00, 'advance');
        $issue = self::YEAR . '-06-20';
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, advance_purchase_invoice_id,
                 advance_paid_amount, vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, reverse_charge,
                 is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", "invoice", ?, 1210.00, "full", ?, ?, ?, ?, ?, 0, 0, 1000.00, 210.00, 1210.00, "received", "40", ?)'
        )->execute([$this->supplierId, $vendor, 'PF-' . $suffix, $advance, $issue, $issue, $issue, $issue, $this->currencyId, $this->userId]);
        $final = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Služba', 1, 'ks', 1000.00, ?, 21.00, 1000.00, 210.00, 1210.00, 0)"
        )->execute([$final, $this->vatRateId]);
        $this->container->get(DocumentAutoPoster::class)->post(
            $this->supplierId, 'purchase_invoice', $final, ['user_id' => $this->userId], $this->userId,
        );
        return [$advance, $final];
    }

    private function payAdvance(int $advance): int
    {
        $tx = $this->transaction($this->statement(), -1210.00, ['match_status' => 'manual', 'posted_at' => self::YEAR . '-06-24']);
        $this->paymentMatch($tx, $advance, 1210.00);
        self::assertSame('posted', $this->service->handleTransaction($tx, $this->userId)['action']);
        return $tx;
    }

    /** @return list<array<string,mixed>> */
    private function postingItems(int $purchaseInvoiceId): array
    {
        $res = $this->callAction(
            $this->container->get(JournalForDocumentAction::class),
            '__invoke',
            'GET',
            'admin',
            [],
            ['source' => 'purchase-invoices', 'id' => (string) $purchaseInvoiceId],
        );
        self::assertSame(200, $res['status'], json_encode($res['body']));
        return $res['body']['items'] ?? [];
    }
}
