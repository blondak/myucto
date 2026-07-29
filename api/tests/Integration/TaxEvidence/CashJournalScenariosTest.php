<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use PHPUnit\Framework\Attributes\Group;

/**
 * Peněžní deník daňové evidence (Epic DE, A2) — povinné scénáře §13.2/§13.3 spec:
 * dedup (R3), virtuální noha C (R2), DPH prorata (R7), výdajové filtry (R8),
 * osvobozený příjem (R9), nezařazeno (R10), reconciliation variance (R5).
 */
#[Group('integration')]
final class CashJournalScenariosTest extends CashJournalTestCase
{
    /** R3: faktura zaplacená bankou se v deníku objeví PRÁVĚ JEDNOU (bankovní pohyb, ne invoice_payment). */
    public function testBankPaidInvoiceAppearsOnce(): void
    {
        $inv = $this->saleInvoice($this->supplierId, ['without' => 10000.0, 'with' => 10000.0, 'status' => 'paid', 'paid_at' => self::YEAR . '-06-15']);
        $st  = $this->statement($this->supplierId, $this->accountA);
        $tx  = $this->bankTx($st, 10000.0);
        $this->invoicePayment($this->supplierId, $inv, 10000.0, 'bank', $tx);

        $res = $this->fullYear($this->supplierId);

        self::assertSame(1, $this->countRows($res, 'bank'), 'Bankovní pohyb má být 1 řádek.');
        self::assertSame(0, $this->countRows($res, 'invoice_payment'), 'invoice_payment source=bank NESMÍ být samostatný řádek (dedup R3).');
        self::assertEqualsWithDelta(10000.0, $res['totals']['prijem_danovy'], 0.01);
    }

    /** R3: faktura zaplacená hotově se objeví jednou (pokladní doklad, ne invoice_payment source=cash). */
    public function testCashPaidInvoiceAppearsOnce(): void
    {
        $inv = $this->saleInvoice($this->supplierId, ['without' => 3000.0, 'with' => 3000.0, 'status' => 'paid', 'paid_at' => self::YEAR . '-06-15']);
        $cd  = $this->cashDoc('in', 'invoice_payment', 3000.0, ['invoice_id' => $inv]);
        // reálný flow: pokladní úhrada vytvoří i invoice_payments source='cash'
        $this->invoicePayment($this->supplierId, $inv, 3000.0, 'cash');

        $res = $this->fullYear($this->supplierId);

        self::assertSame(1, $this->countRows($res, 'cash'), 'Pokladní doklad = 1 řádek.');
        self::assertSame(0, $this->countRows($res, 'invoice_payment'), 'invoice_payment source=cash NESMÍ být samostatný řádek (dedup R3).');
        self::assertEqualsWithDelta(3000.0, $res['totals']['prijem_danovy'], 0.01);
    }

    /** R2: OSVČ bez importu výpisů — mark_paid faktura se v deníku objeví jako daňový příjem (noha C). */
    public function testVirtualIncomeLegAppears(): void
    {
        $inv = $this->saleInvoice($this->supplierId, ['without' => 7000.0, 'with' => 7000.0, 'status' => 'paid', 'paid_at' => self::YEAR . '-06-15']);
        $this->invoicePayment($this->supplierId, $inv, 7000.0, 'mark_paid');

        $res = $this->fullYear($this->supplierId);

        self::assertSame(1, $this->countRows($res, 'invoice_payment'), 'Virtuální příjmová noha C musí být přítomna (R2).');
        self::assertEqualsWithDelta(7000.0, $res['totals']['prijem_danovy'], 0.01);
    }

    /** R2: ručně zaplacená PF bez payment_matches i bez cash dokladu se objeví jako výdaj (noha C). */
    public function testVirtualExpenseLegAppears(): void
    {
        $this->purchaseInvoice($this->supplierId, ['without' => 4000.0, 'with' => 4000.0, 'paid_at' => self::YEAR . '-06-20', 'status' => 'paid']);

        $res = $this->fullYear($this->supplierId);

        self::assertSame(1, $this->countRows($res, 'purchase_invoice'), 'Virtuální výdajová noha C musí být přítomna (R2).');
        self::assertEqualsWithDelta(4000.0, $res['totals']['vydaj_danovy'], 0.01);
    }

    /** R7: částečná úhrada faktury u plátce DPH → base × ratio do daňového příjmu, zbytek DPH nedaňový. */
    public function testVatProrationOnPartialPayment(): void
    {
        $this->setVatPayer($this->supplierId, true);
        // 10 000 základ + 2 100 DPH = 12 100 brutto; úhrada poloviny 6 050.
        $inv = $this->saleInvoice($this->supplierId, ['without' => 10000.0, 'with' => 12100.0, 'status' => 'issued']);
        $this->invoicePayment($this->supplierId, $inv, 6050.0, 'manual');

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(5000.0, $res['totals']['prijem_danovy'], 0.01, 'Základ = 6050 × 10000/12100 = 5000.');
        self::assertEqualsWithDelta(1050.0, $res['totals']['prijem_nedanovy'], 0.01, 'DPH složka = 1050 → nedaňový.');
    }

    public function testNonDeductibleVatExpenseIsConsistentForVirtualBankAndCashPayments(): void
    {
        $this->setVatPayer($this->supplierId, true);

        foreach ([['full', 100, 1000.0], ['none', 0, 1210.0], ['proportional', 50, 1105.0]] as [$mode, $percent, $expected]) {
            $invoice = $this->purchaseInvoice($this->supplierId, [
                'without' => 1000.0, 'with' => 1210.0, 'vat_deduction' => $mode,
                'vat_deduction_percent' => $percent, 'paid_at' => self::YEAR . '-06-20', 'status' => 'paid',
            ]);
            $result = $this->fullYear($this->supplierId, true);
            $row = array_values(array_filter(
                $result['rows'],
                static fn (array $r): bool => $r['source_type'] === 'purchase_invoice' && $r['source_id'] === $invoice,
            ))[0];
            self::assertEqualsWithDelta($expected, $row['base'], 0.01, "Virtuální úhrada, režim {$mode}.");
        }

        $statement = $this->statement($this->supplierId, $this->accountA);
        $bankTransaction = $this->bankTx($statement, -3630.0, ['match_status' => 'auto_exact']);
        foreach ([['full', 100], ['none', 0], ['proportional', 50]] as [$mode, $percent]) {
            $invoice = $this->purchaseInvoice($this->supplierId, [
                'without' => 1000.0, 'with' => 1210.0, 'vat_deduction' => $mode,
                'vat_deduction_percent' => $percent, 'status' => 'paid',
            ]);
            $this->paymentMatch($this->supplierId, $bankTransaction, $invoice, 1210.0);
        }
        $bankResult = $this->fullYear($this->supplierId, true);
        $bankRow = array_values(array_filter(
            $bankResult['rows'],
            static fn (array $r): bool => $r['source_type'] === 'bank' && $r['source_id'] === $bankTransaction,
        ))[0];
        self::assertEqualsWithDelta(3315.0, $bankRow['base'], 0.01, 'Banka musí sečíst alokace 1000 + 1210 + 1105.');

        foreach ([['full', 100], ['none', 0], ['proportional', 50]] as [$mode, $percent]) {
            $this->cashDoc('out', 'purchase', 1210.0, [], [
                'base' => 1000.0, 'vat' => 210.0, 'deduction' => $mode, 'percent' => $percent,
            ]);
        }
        $cashResult = $this->fullYear($this->supplierId, true);
        $cashBase = array_sum(array_map(
            static fn (array $r): float => $r['source_type'] === 'cash' && $r['direction'] === 'out' ? (float) $r['base'] : 0.0,
            $cashResult['rows'],
        ));
        self::assertEqualsWithDelta(3315.0, $cashBase, 0.01, 'Pokladna musí použít stejný výpočet nároku na odpočet.');
    }

    public function testVatPayerStatusIsResolvedAtMovementDate(): void
    {
        $this->setVatPayer($this->supplierId, true);
        $this->db->pdo()->prepare('DELETE FROM supplier_vat_status_history WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, created_by)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, self::YEAR . '-01-01', 0, $this->userId]);
        $stmt->execute([$this->supplierId, self::YEAR . '-07-01', 1, $this->userId]);

        $june = $this->purchaseInvoice($this->supplierId, [
            'without' => 1000.0, 'with' => 1210.0, 'vat_deduction' => 'full',
            'paid_at' => self::YEAR . '-06-30', 'status' => 'paid',
        ]);
        $july = $this->purchaseInvoice($this->supplierId, [
            'without' => 1000.0, 'with' => 1210.0, 'vat_deduction' => 'full',
            'paid_at' => self::YEAR . '-07-01', 'status' => 'paid',
        ]);

        $result = $this->fullYear($this->supplierId, true);
        $rows = [];
        foreach ($result['rows'] as $row) {
            if (in_array($row['source_id'], [$june, $july], true)) {
                $rows[$row['source_id']] = $row;
            }
        }
        self::assertEqualsWithDelta(1210.0, $rows[$june]['base'], 0.01, '30. 6. je neplátce, výdaj je brutto.');
        self::assertEqualsWithDelta(1000.0, $rows[$july]['base'], 0.01, '1. 7. je plátce, výdaj je netto.');
    }

    /** Neplátce: celé brutto je základ (žádný DPH rozpad). */
    public function testNonVatPayerWholeAmountIsBase(): void
    {
        $this->setVatPayer($this->supplierId, false);
        $inv = $this->saleInvoice($this->supplierId, ['without' => 8000.0, 'with' => 8000.0, 'status' => 'issued']);
        $this->invoicePayment($this->supplierId, $inv, 8000.0, 'manual');

        $res = $this->fullYear($this->supplierId);
        self::assertEqualsWithDelta(8000.0, $res['totals']['prijem_danovy'], 0.01);
        self::assertEqualsWithDelta(0.0, $res['totals']['prijem_nedanovy'], 0.01);
    }

    /** R8: nedaňová PF (tax_deductible=0) a zálohová (advance) → expense_nontax, ne daňový výdaj. */
    public function testNonDeductiblePurchaseExcludedFromTaxExpense(): void
    {
        $this->purchaseInvoice($this->supplierId, ['without' => 2000.0, 'with' => 2000.0, 'tax_deductible' => 0, 'paid_at' => self::YEAR . '-06-20', 'status' => 'paid']);
        $this->purchaseInvoice($this->supplierId, ['without' => 1500.0, 'with' => 1500.0, 'document_kind' => 'advance', 'paid_at' => self::YEAR . '-06-21', 'status' => 'paid']);

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(1500.0, $res['totals']['vydaj_danovy'], 0.01, 'Zaplacená záloha je v daňové evidenci výdajem; nedaňová PF zůstává vyloučená.');
        self::assertEqualsWithDelta(2000.0, $res['totals']['vydaj_nedanovy'], 0.01);
    }

    /**
     * R8: daňový doklad k poskytnuté záloze (DDKP, § 28 ZDPH) NENÍ daňový výdaj —
     * peníze odešly už na zálohové faktuře a v kasové bázi § 7b se výdaj uplatnil tam.
     * DDKP jen dokládá nárok na odpočet DPH.
     *
     * Bez filtru v TaxExpenseAllocationCalculator::forPurchaseInvoice by DDKP vytvořil
     * DRUHÝ daňový výdaj v plné výši → dvojí uplatnění v základu DPFO § 7.
     */
    public function testAdvanceVatDocumentIsNotTaxExpense(): void
    {
        $this->purchaseInvoice($this->supplierId, [
            'without' => 1000.0, 'with' => 1000.0, 'document_kind' => 'advance',
            'paid_at' => self::YEAR . '-06-20', 'status' => 'paid',
        ]);
        $this->purchaseInvoice($this->supplierId, [
            'without' => 1000.0, 'with' => 1000.0, 'document_kind' => 'tax_document',
            'paid_at' => self::YEAR . '-06-21', 'status' => 'paid',
        ]);

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(
            1000.0,
            $res['totals']['vydaj_danovy'],
            0.01,
            'DDKP nesmí vygenerovat druhý daňový výdaj — výdaj byl uplatněn už u zaplacené zálohy.',
        );
    }

    /**
     * R11 pro výdajovou stranu: vratka z přijatého dobropisu (peníze přišly ZPĚT,
     * direction='in') musí daňový výdaj SNÍŽIT, ne zvýšit.
     *
     * expenseAlloc dřív parametr $direction vůbec nedostával — na rozdíl od zrcadlového
     * incomeAlloc — takže vratka výdaj zvyšovala. Chyba byla 2× částka v základu DPFO § 7.
     */
    public function testPurchaseRefundDecreasesTaxExpense(): void
    {
        $pf = $this->purchaseInvoice($this->supplierId, [
            'without' => 5000.0, 'with' => 5000.0,
            'paid_at' => self::YEAR . '-06-10', 'status' => 'paid',
        ]);
        // Úhrada hotově (out) a následná vratka části ceny (in) — obojí na tutéž PF.
        // Pokladní doklady zároveň vyřadí virtuální nohu D (dedup R3), takže v deníku
        // jsou právě tyto dva pohyby.
        $this->cashDoc('out', 'purchase_payment', 5000.0, ['purchase_invoice_id' => $pf], null, self::YEAR . '-06-10');
        $this->cashDoc('in', 'purchase_payment', 2000.0, ['purchase_invoice_id' => $pf], null, self::YEAR . '-06-25');

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(
            3000.0,
            $res['totals']['vydaj_danovy'],
            0.01,
            'Vratka (direction=in) musí výdaj snížit: 5000 − 2000 = 3000, ne 5000 + 2000.',
        );
    }

    public function testFixedAssetLimitComesFromYearConstants(): void
    {
        $this->purchaseInvoice($this->supplierId, [
            'without' => 80000.0, 'with' => 80000.0, 'is_fixed_asset' => 1,
            'paid_at' => self::YEAR . '-06-20', 'status' => 'paid',
        ]);
        $this->purchaseInvoice($this->supplierId, [
            'without' => 80001.0, 'with' => 80001.0, 'is_fixed_asset' => 1,
            'paid_at' => self::YEAR . '-06-21', 'status' => 'paid',
        ]);

        $result = $this->fullYear($this->supplierId);
        self::assertEqualsWithDelta(80000.0, $result['totals']['vydaj_danovy'], 0.01);
        self::assertEqualsWithDelta(80001.0, $result['totals']['vydaj_nedanovy'], 0.01);
    }

    public function testVatDeductibleFixedAssetUsesNetEntryPrice(): void
    {
        $this->purchaseInvoice($this->supplierId, [
            'without' => 70000.0, 'with' => 84700.0, 'is_fixed_asset' => 1,
            'vat_deduction' => 'full', 'paid_at' => self::YEAR . '-06-20', 'status' => 'paid',
        ]);

        $result = $this->fullYear($this->supplierId, true);

        self::assertEqualsWithDelta(70000.0, $result['totals']['vydaj_danovy'], 0.01);
        self::assertEqualsWithDelta(14700.0, $result['totals']['vydaj_nedanovy'], 0.01);
    }

    public function testNonDeductibleVatFixedAssetUsesGrossEntryPrice(): void
    {
        $this->purchaseInvoice($this->supplierId, [
            'without' => 70000.0, 'with' => 84700.0, 'is_fixed_asset' => 1,
            'vat_deduction' => 'none', 'paid_at' => self::YEAR . '-06-20', 'status' => 'paid',
        ]);

        $result = $this->fullYear($this->supplierId, true);

        self::assertEqualsWithDelta(0.0, $result['totals']['vydaj_danovy'], 0.01);
        self::assertEqualsWithDelta(84700.0, $result['totals']['vydaj_nedanovy'], 0.01);
    }

    /** R9: osvobozený příjem (income_tax_exempt=1) → samostatný bucket, mimo daňový základ. */
    public function testExemptIncomeGoesToExemptBucket(): void
    {
        $inv = $this->saleInvoice($this->supplierId, ['without' => 50000.0, 'with' => 50000.0, 'status' => 'paid', 'paid_at' => self::YEAR . '-06-15', 'income_tax_exempt' => 1]);
        $this->invoicePayment($this->supplierId, $inv, 50000.0, 'manual');

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(0.0, $res['totals']['prijem_danovy'], 0.01, 'Osvobozený příjem nesmí být v daňovém základu (R9).');
        self::assertEqualsWithDelta(50000.0, $res['totals']['prijem_osvobozeny'], 0.01);
    }

    /** R10: nezařazený PŘÍCHOZÍ bankovní pohyb → mimo totály + blokující varování, NIKDY tiše nedaňový. */
    public function testUnclassifiedIncomingBankIsNezarazenoWithWarning(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $this->bankTx($st, 15000.0, ['description' => 'Neznámý příjem', 'variable_symbol' => null]);

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(0.0, $res['totals']['prijem_danovy'], 0.01);
        self::assertEqualsWithDelta(0.0, $res['totals']['prijem_nedanovy'], 0.01, 'Nezařazený příjem NESMÍ tiše spadnout do nedaňového (R10).');
        self::assertEqualsWithDelta(15000.0, $res['totals']['nezarazeno'], 0.01);
        self::assertNotEmpty($res['warnings']);
        self::assertTrue($res['warnings'][0]['blocking'], 'Příchozí nezařazený pohyb je blokující (R10).');
    }

    /** R10 + 1027: override přeřadí nezařazený pohyb do daňového příjmu. */
    public function testClassificationOverrideMovesBankMovement(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($st, 15000.0, ['description' => 'Neznámý příjem']);
        $this->classifyOverride($this->supplierId, 'bank', $tx, 'income_taxable');

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(15000.0, $res['totals']['prijem_danovy'], 0.01, 'Override 1027 → daňový příjem.');
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.01);
        self::assertEmpty($res['warnings']);
    }

    /** R5: reconciliation je VYSVĚTLENÁ VARIANCE, ne rovnostní assert (a nevyhazuje). */
    public function testReconciliationReportsVarianceNotEquality(): void
    {
        // Částečná úhrada faktury, která NENÍ status='paid' → annualIncome ji nezná, deník ano.
        $inv = $this->saleInvoice($this->supplierId, ['without' => 10000.0, 'with' => 12100.0, 'status' => 'issued']);
        $this->invoicePayment($this->supplierId, $inv, 6050.0, 'manual');

        $res = $this->fullYear($this->supplierId);
        $checks = $res['checks'];

        self::assertFalse($checks['is_equal_assert'], 'R5: reconciliation NENÍ rovnost.');
        self::assertEqualsWithDelta(5000.0, $checks['denik_prijem_danovy'], 0.01);
        self::assertEqualsWithDelta(0.0, $checks['annual_income'], 0.01, 'annualIncome počítá jen status=paid.');
        self::assertEqualsWithDelta(5000.0, $checks['variance'], 0.01, 'Variance vyčíslena, ne asserted equal.');
        self::assertEqualsWithDelta(5000.0, $checks['explanations']['partial_payments'], 0.01, 'Rozdíl vysvětlen částečnou úhradou.');
    }

    /** Běžný zůstatek: opening + Σ pohybů v rozsahu = closing. */
    public function testRunningBalanceOpeningPlusMovementsEqualsClosing(): void
    {
        // Pohyb před rozsahem (loni) → opening.
        $this->cashDoc('in', 'sale', 1000.0, [], null, (self::YEAR - 1) . '-06-15');
        // Pohyby v rozsahu.
        $this->cashDoc('in', 'sale', 2000.0, [], null, self::YEAR . '-03-01');
        $this->cashDoc('out', 'purchase', 500.0, [], null, self::YEAR . '-04-01');

        $res = $this->fullYear($this->supplierId);

        self::assertEqualsWithDelta(1000.0, $res['opening_balance'], 0.01);
        self::assertEqualsWithDelta(2500.0, $res['closing_balance'], 0.01, 'opening 1000 + (2000 - 500) = 2500.');
        // Poslední řádek nese closing.
        $last = end($res['rows']);
        self::assertEqualsWithDelta(2500.0, $last['running_balance'], 0.01);
    }
}
