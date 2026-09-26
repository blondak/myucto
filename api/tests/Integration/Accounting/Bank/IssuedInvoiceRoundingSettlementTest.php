<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class IssuedInvoiceRoundingSettlementTest extends BankPostingTestCase
{
    public function testRoundedInvoicePaymentDoesNotPostRoundingTwice(): void
    {
        foreach ([[916.44, 916.0, -0.44], [9.70, 10.0, 0.30]] as [$gross, $payable, $rounding]) {
            $client = $this->client('Syntetický odběratel zaokrouhlení');
            $inv = $this->saleInvoice('ROUND-' . $client, $client, $payable);
            $this->db->pdo()->prepare('UPDATE invoices SET total_without_vat = ?, rounding = ?, rounding_mode = "whole_czk" WHERE id = ?')
                ->execute([$gross, $rounding, $inv]);
            $this->postPredpis('invoice', $inv, '311', '602', $payable);
            $tx = $this->transaction($this->statement(), $payable, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
            $this->invoicePayment($inv, $tx, $payable);
            self::assertFalse($this->service->normalizeRoundingFullInvoice($this->supplierId, $tx));
            $res = $this->service->handleTransaction($tx, $this->userId);
            self::assertSame('posted', $res['action']);
            $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
            self::assertEqualsWithDelta($payable, $byAcc['221']['debit'], 0.001);
            self::assertEqualsWithDelta($payable, $byAcc['311']['credit'], 0.001);
            self::assertArrayNotHasKey('548', $byAcc);
            self::assertArrayNotHasKey('648', $byAcc);
            $balance = $this->db->pdo()->prepare(
                "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
                   FROM journal_entries je
                   JOIN journal_entry_lines l ON l.entry_id = je.id
                   JOIN chart_of_accounts a ON a.id = l.account_id
                  WHERE je.supplier_id = ? AND je.reversed_by IS NULL AND a.account_code LIKE '311%'
                    AND ((je.source_type = 'invoice' AND je.source_id = ?)
                      OR (je.source_type = 'bank' AND je.source_id = ?))"
            );
            $balance->execute([$this->supplierId, $inv, $tx]);
            self::assertEqualsWithDelta(0.0, (float) $balance->fetchColumn(), 0.001);
            self::assertSame((int) $res['entry_id'], $this->service->postMatched($this->supplierId, $tx, $this->userId));
        }
    }
}
