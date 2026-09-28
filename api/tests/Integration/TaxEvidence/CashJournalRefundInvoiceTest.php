<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use PHPUnit\Framework\Attributes\Group;

/**
 * Peněžní deník: odchozí vratka k faktuře k vyplacení (vyúčtování vratných obalů,
 * −608 Kč) snižuje příjem stejně jako vratka dobropisu. Klasifikace bankovního pohybu
 * nerozhoduje podle typu dokladu, ale podle vazby na vydaný doklad a směru pohybu.
 */
#[Group('integration')]
final class CashJournalRefundInvoiceTest extends CashJournalTestCase
{
    /** @return array{0:float,1:int} daňový příjem a počet bankovních řádků */
    private function refundThrough(string $type): array
    {
        $doc = $this->saleInvoice($this->supplierId, [
            'type' => $type, 'without' => -608.0, 'with' => -608.0,
            'status' => 'paid', 'paid_at' => self::YEAR . '-06-15',
        ]);
        $statement = $this->statement($this->supplierId, $this->accountA);
        $this->bankTx($statement, -608.0, ['matched_invoice_id' => $doc, 'match_status' => 'manual']);

        $res = $this->fullYear($this->supplierId, false);
        return [(float) $res['totals']['prijem_danovy'], $this->countRows($res, 'bank')];
    }

    public function testCreditNoteRefundLowersIncome(): void
    {
        [$income, $rows] = $this->refundThrough('credit_note');
        self::assertEqualsWithDelta(-608.0, $income, 0.01);
        self::assertSame(1, $rows);
    }

    public function testRefundInvoiceLowersIncomeLikeCreditNote(): void
    {
        [$income, $rows] = $this->refundThrough('invoice');
        self::assertEqualsWithDelta(-608.0, $income, 0.01, 'Vratka k faktuře k vyplacení = záporný příjem jako u dobropisu.');
        self::assertSame(1, $rows);
    }
}
