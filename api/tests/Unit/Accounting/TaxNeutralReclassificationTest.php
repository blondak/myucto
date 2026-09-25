<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\TaxNeutralReclassification as R;
use PHPUnit\Framework\TestCase;

/**
 * Přepis zápisu v datu zamčeném podaným DPH smí projít jen tehdy, když jde o čistý
 * přesun mezi účty téže třídy bez dopadu do daní. Každý test níž je jedna cesta,
 * kudy by se daňový dopad dal protlačit „přeúčtováním".
 */
final class TaxNeutralReclassificationTest extends TestCase
{
    private const ACCOUNTS = [
        1  => ['code' => '511', 'account_type' => 'expense', 'tax_deductibility' => 'deductible'],
        2  => ['code' => '518.100', 'account_type' => 'expense', 'tax_deductibility' => 'deductible'],
        3  => ['code' => '518.990', 'account_type' => 'expense', 'tax_deductibility' => 'non_deductible'],
        4  => ['code' => '343.100', 'account_type' => 'liability', 'tax_deductibility' => 'deductible'],
        5  => ['code' => '321.100', 'account_type' => 'liability', 'tax_deductibility' => 'deductible'],
        6  => ['code' => '042.100', 'account_type' => 'asset', 'tax_deductibility' => 'deductible'],
        7  => ['code' => '511.100', 'account_type' => 'expense', 'tax_deductibility' => 'deductible'],
        8  => ['code' => '325', 'account_type' => 'liability', 'tax_deductibility' => 'deductible'],
        9  => ['code' => '710', 'account_type' => 'closing', 'tax_deductibility' => 'deductible'],
        10 => ['code' => '501.200', 'account_type' => 'expense', 'tax_deductibility' => 'deductible'],
        11 => ['code' => '548.100', 'account_type' => 'expense', 'tax_deductibility' => 'deductible'],
        12 => ['code' => '221.100', 'account_type' => 'asset', 'tax_deductibility' => 'deductible'],
        13 => ['code' => '381', 'account_type' => 'asset', 'tax_deductibility' => 'deductible'],
    ];

    /** Přijatá faktura se slevou zaúčtovanou samostatně jako Dal 518 (stav před opravou). */
    private static function discountAsCredit(): array
    {
        return [
            ['account_id' => 10, 'side' => 'debit', 'amount' => 4808.42],
            ['account_id' => 2, 'side' => 'credit', 'amount' => 40.20],
            ['account_id' => 4, 'side' => 'debit', 'amount' => 1001.19],
            ['account_id' => 5, 'side' => 'credit', 'amount' => 5769.42],
            ['account_id' => 11, 'side' => 'debit', 'amount' => 0.01],
        ];
    }

    /**
     * Sleva se rozpustí do zboží: 501 klesne, 518 z Dal 40,20 na MD 136,17. Součet stran
     * se změní (5 809,62 → 5 769,42), ale DPH, závazek ani výsledek ne — jen se sloučily
     * protisměrné řádky nákladových účtů. Dřív to pravidlo odmítlo jako změnu částek.
     */
    public function testMergingOpposingExpenseLinesIsTaxNeutral(): void
    {
        $after = [
            ['account_id' => 10, 'side' => 'debit', 'amount' => 4632.05],
            ['account_id' => 2, 'side' => 'debit', 'amount' => 136.17],
            ['account_id' => 4, 'side' => 'debit', 'amount' => 1001.19],
            ['account_id' => 11, 'side' => 'debit', 'amount' => 0.01],
            ['account_id' => 5, 'side' => 'credit', 'amount' => 5769.42],
        ];

        self::assertNull(R::violation(self::discountAsCredit(), $after, self::ACCOUNTS));
        self::assertNull(R::violation(self::discountAsCredit(), $after, self::ACCOUNTS, false));
    }

    /** Totéž sloučení, ale se změnou závazku, DPH nebo výsledku po podání DPPO, dál neprojde. */
    public function testMergingWithRealChangeIsStillRejected(): void
    {
        $liability = [
            ['account_id' => 10, 'side' => 'debit', 'amount' => 4632.05],
            ['account_id' => 2, 'side' => 'debit', 'amount' => 136.17],
            ['account_id' => 4, 'side' => 'debit', 'amount' => 1001.19],
            ['account_id' => 11, 'side' => 'debit', 'amount' => 40.21],
            ['account_id' => 5, 'side' => 'credit', 'amount' => 5809.62],
        ];
        self::assertSame(R::AMOUNTS_CHANGED, R::violation(self::discountAsCredit(), $liability, self::ACCOUNTS, false));

        $vat = [
            ['account_id' => 10, 'side' => 'debit', 'amount' => 4632.05],
            ['account_id' => 2, 'side' => 'debit', 'amount' => 137.17],
            ['account_id' => 4, 'side' => 'debit', 'amount' => 1000.19],
            ['account_id' => 11, 'side' => 'debit', 'amount' => 0.01],
            ['account_id' => 5, 'side' => 'credit', 'amount' => 5769.42],
        ];
        self::assertSame(R::TAX_ACCOUNT_CHANGED, R::violation(self::discountAsCredit(), $vat, self::ACCOUNTS, false));

        $result = [
            ['account_id' => 10, 'side' => 'debit', 'amount' => 4632.05],
            ['account_id' => 13, 'side' => 'debit', 'amount' => 136.17],
            ['account_id' => 4, 'side' => 'debit', 'amount' => 1001.19],
            ['account_id' => 11, 'side' => 'debit', 'amount' => 0.01],
            ['account_id' => 5, 'side' => 'credit', 'amount' => 5769.42],
        ];
        self::assertSame(R::ACCOUNT_CLASS_CHANGED, R::violation(self::discountAsCredit(), $result, self::ACCOUNTS));
    }

    /** Změna bankovní částky je skutečnost, ne kontace. */
    public function testBankAmountChangeIsRejected(): void
    {
        $before = [
            ['account_id' => 5, 'side' => 'debit', 'amount' => 1000.00],
            ['account_id' => 12, 'side' => 'credit', 'amount' => 1000.00],
        ];
        $after = [
            ['account_id' => 5, 'side' => 'debit', 'amount' => 1000.00],
            ['account_id' => 12, 'side' => 'credit', 'amount' => 999.00],
            ['account_id' => 13, 'side' => 'credit', 'amount' => 1.00],
        ];

        self::assertSame(R::AMOUNTS_CHANGED, R::violation($before, $after, self::ACCOUNTS, false));

        // Záměna banky za závazek: netto 221 i 321 se změní o totéž opačně. Peníze
        // se hlídají zvlášť, jinak by to prošlo jako přesun uvnitř jedné skupiny.
        $both = [
            ['account_id' => 5, 'side' => 'debit', 'amount' => 1100.00],
            ['account_id' => 12, 'side' => 'credit', 'amount' => 1100.00],
        ];
        self::assertSame(R::AMOUNTS_CHANGED, R::violation($before, $both, self::ACCOUNTS, false));
    }

    /** Zápis přijaté faktury 205 320 + DPH 43 117,20 na nákladový účet $expenseId. */
    private static function purchase(int $expenseId, float $vat = 43117.20): array
    {
        return [
            ['account_id' => $expenseId, 'side' => 'debit', 'amount' => 205320.00],
            ['account_id' => 4, 'side' => 'debit', 'amount' => $vat],
            ['account_id' => 5, 'side' => 'credit', 'amount' => round(205320.00 + $vat, 2)],
        ];
    }

    /** Reálný případ: 511 → 518.100, DPH ani závazek se nehýbou. */
    public function testExpenseAccountSwapIsTaxNeutral(): void
    {
        self::assertNull(R::violation(self::purchase(1), self::purchase(2), self::ACCOUNTS));
    }

    public function testUnchangedEntryIsTaxNeutral(): void
    {
        self::assertNull(R::violation(self::purchase(2), self::purchase(2), self::ACCOUNTS));
    }

    /** Rozdělení nákladu na dva účty téže třídy je pořád jen přesun. */
    public function testSplittingWithinClassIsTaxNeutral(): void
    {
        $after = [
            ['account_id' => 2, 'side' => 'debit', 'amount' => 200000.00],
            ['account_id' => 7, 'side' => 'debit', 'amount' => 5320.00],
            ['account_id' => 4, 'side' => 'debit', 'amount' => 43117.20],
            ['account_id' => 5, 'side' => 'credit', 'amount' => 248437.20],
        ];

        self::assertNull(R::violation(self::purchase(1), $after, self::ACCOUNTS));
    }

    /** Změna DPH v zamčeném datu by rozešla deník s podaným přiznáním. */
    public function testVatChangeIsRejected(): void
    {
        self::assertSame(
            R::TAX_ACCOUNT_CHANGED,
            R::violation(self::purchase(1), self::purchase(1, 40000.00), self::ACCOUNTS),
        );
    }

    /** Přesun na nedaňovou analytiku mění základ daně z příjmů. */
    public function testDeductibilityChangeIsRejected(): void
    {
        self::assertSame(
            R::TAX_DEDUCTIBILITY_CHANGED,
            R::violation(self::purchase(2), self::purchase(3), self::ACCOUNTS),
        );
    }

    /** Náklad → dlouhodobý majetek je jiná třída (a jiné odpisy), ne přeúčtování. */
    public function testClassChangeIsRejected(): void
    {
        self::assertSame(
            R::ACCOUNT_CLASS_CHANGED,
            R::violation(self::purchase(1), self::purchase(6), self::ACCOUNTS),
        );
    }

    /** Snížení nákladu i závazku mění částky, ne jen účty. */
    public function testAmountChangeIsRejected(): void
    {
        $after = [
            ['account_id' => 1, 'side' => 'debit', 'amount' => 200000.00],
            ['account_id' => 4, 'side' => 'debit', 'amount' => 43117.20],
            ['account_id' => 5, 'side' => 'credit', 'amount' => 243117.20],
        ];

        self::assertSame(R::AMOUNTS_CHANGED, R::violation(self::purchase(1), $after, self::ACCOUNTS));
    }

    /** Přesun uvnitř třídy 3 bez daňového účtu projde (321 → 325). */
    public function testBalanceSheetMoveWithinClassIsTaxNeutral(): void
    {
        $after = self::purchase(1);
        $after[2]['account_id'] = 8;

        self::assertNull(R::violation(self::purchase(1), $after, self::ACCOUNTS));
    }

    /**
     * Dokud není podané přiznání k dani z příjmů, smí se měnit i třída a uznatelnost:
     * reálný případ je dodatečně doplněné časové rozlišení 518 → 381.
     */
    public function testUnfiledIncomeTaxAllowsClassAndDeductibilityChange(): void
    {
        self::assertNull(R::violation(self::purchase(1), self::purchase(6), self::ACCOUNTS, false));
        self::assertNull(R::violation(self::purchase(2), self::purchase(3), self::ACCOUNTS, false));
    }

    /** DPH a částky jsou nedotknutelné i bez podaného přiznání k dani z příjmů. */
    public function testUnfiledIncomeTaxStillProtectsVatAndAmounts(): void
    {
        self::assertSame(
            R::TAX_ACCOUNT_CHANGED,
            R::violation(self::purchase(1), self::purchase(1, 40000.00), self::ACCOUNTS, false),
        );
        $after = [
            ['account_id' => 1, 'side' => 'debit', 'amount' => 200000.00],
            ['account_id' => 4, 'side' => 'debit', 'amount' => 43117.20],
            ['account_id' => 5, 'side' => 'credit', 'amount' => 243117.20],
        ];
        self::assertSame(R::AMOUNTS_CHANGED, R::violation(self::purchase(1), $after, self::ACCOUNTS, false));
    }

    public function testClosingAccountIsRejected(): void
    {
        self::assertSame(
            R::SPECIAL_ACCOUNT,
            R::violation(self::purchase(1), self::purchase(9), self::ACCOUNTS),
        );
    }

    public function testUnknownAccountIsRejected(): void
    {
        self::assertSame(
            R::UNKNOWN_ACCOUNT,
            R::violation(self::purchase(1), self::purchase(99), self::ACCOUNTS),
        );
    }
}
