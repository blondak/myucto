<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use PHPUnit\Framework\Attributes\Group;

/**
 * Výplata dokladu k vyplacení v hotovosti (VPD s účelem `invoice_payment`) je
 * v peněžním deníku záporný příjem, stejně jako bankovní vratka dobropisu.
 */
#[Group('integration')]
final class CashJournalRefundTest extends CashJournalTestCase
{
    public function testCashRefundOfNegativeInvoiceReducesIncome(): void
    {
        $inv = $this->saleInvoice($this->supplierId, [
            'without' => -608.0, 'with' => -608.0, 'status' => 'paid', 'paid_at' => self::YEAR . '-06-15',
        ]);
        $this->cashDoc('out', 'invoice_payment', 608.0, ['invoice_id' => $inv]);

        $res = $this->fullYear($this->supplierId, false);

        self::assertSame(1, $this->countRows($res, 'cash'));
        self::assertEqualsWithDelta(-608.0, $res['totals']['prijem_danovy'], 0.01);
    }
}
