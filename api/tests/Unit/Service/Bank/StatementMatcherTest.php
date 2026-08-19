<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use PHPUnit\Framework\TestCase;

/**
 * Testuje čistou měnovou logiku párování — expectedMatch() (private, přes reflexi,
 * stejně jako PdfTotalExtractorTest::parseMoney). DB se nedotýká, takže běží i v CI.
 *
 * expectedMatch převede částku faktury do měny transakce + vrátí tolerance:
 *   - stejná měna / neznámá měna tx → přímé porovnání (0.05 / 1.0 Kč),
 *   - CZK platba cizoměnové faktury → částka × kurz faktury, relativní tolerance 4 %,
 *   - cizí účet × jiná měna faktury → null (nepárovat).
 */
final class StatementMatcherTest extends TestCase
{
    private StatementMatcher $matcher;

    protected function setUp(): void
    {
        // expectedMatch nepoužívá DB ani finalCreator → stuby stačí.
        $this->matcher = new StatementMatcher(
            $this->createStub(Connection::class),
            $this->createStub(FinalFromProformaCreator::class),
        );
    }

    /**
     * @return array{expected: float, exact: float, partial: float}|null
     */
    private function expectedMatch(float $amount, string $invCcy, float $rate, ?string $txCcy): ?array
    {
        $ref = new \ReflectionMethod($this->matcher, 'expectedMatch');
        /** @var array{expected: float, exact: float, partial: float}|null $r */
        $r = $ref->invoke($this->matcher, $amount, $invCcy, $rate, $txCcy);
        return $r;
    }

    private function paymentAmount(
        float $txAmount,
        string $invCcy,
        float $rate,
        ?string $txCcy,
        float $remaining,
        bool $settleRemaining = false,
    ): float
    {
        $ref = new \ReflectionMethod($this->matcher, 'txAmountInInvoiceCurrency');
        return (float) $ref->invoke(
            $this->matcher,
            $txAmount,
            ['currency' => $invCcy, 'exchange_rate' => $rate],
            $txCcy,
            $remaining,
            $settleRemaining,
        );
    }

    public function testSameCurrencyComparesDirectly(): void
    {
        $m = $this->expectedMatch(2520.0, 'EUR', 24.36, 'EUR');
        self::assertNotNull($m);
        self::assertSame(2520.0, $m['expected']);
        self::assertSame(0.05, $m['exact']);
        self::assertSame(1.0, $m['partial']);
    }

    public function testNullTxCurrencyFallsBackToDirect(): void
    {
        // Legacy výpisy bez měny — porovnej napřímo (zpětná kompatibilita).
        $m = $this->expectedMatch(1000.0, 'EUR', 25.0, null);
        self::assertNotNull($m);
        self::assertSame(1000.0, $m['expected']);
        self::assertSame(0.05, $m['exact']);
    }

    public function testCzkPaymentOfForeignInvoiceConvertsByRate(): void
    {
        // 2520 EUR × 25 = 63000 CZK; tolerance 4 % = 2520 CZK; cross-currency = jeden tier.
        $m = $this->expectedMatch(2520.0, 'EUR', 25.0, 'CZK');
        self::assertNotNull($m);
        self::assertSame(63000.0, $m['expected']);
        self::assertEqualsWithDelta(2520.0, $m['exact'], 0.001);
        self::assertSame($m['exact'], $m['partial']);
    }

    public function testCzkExpectedAmountIsRoundedToCents(): void
    {
        // 123,45 × 24,50 = 3 024,525; bankovní pohyb lze porovnávat jen na haléře.
        $m = $this->expectedMatch(123.45, 'EUR', 24.5, 'CZK');
        self::assertNotNull($m);
        self::assertSame(3024.53, $m['expected']);
    }

    public function testCzkPaymentOfCzkInvoiceIsDirect(): void
    {
        $m = $this->expectedMatch(3049.2, 'CZK', 1.0, 'CZK');
        self::assertNotNull($m);
        self::assertSame(3049.2, $m['expected']);
        self::assertSame(0.05, $m['exact']);
    }

    public function testForeignAccountWithDifferentInvoiceCurrencyIsNull(): void
    {
        // EUR výpis × USD faktura — bez kurzu transakce nepřevedeme.
        self::assertNull($this->expectedMatch(1000.0, 'USD', 1.0, 'EUR'));
    }

    public function testForeignAccountWithCzkInvoiceIsNull(): void
    {
        // EUR výpis × CZK faktura stejného VS+amount — původní nebezpečný případ zůstává unmatched.
        self::assertNull($this->expectedMatch(1000.0, 'CZK', 1.0, 'EUR'));
    }

    public function testCurrencyComparisonIsCaseInsensitive(): void
    {
        $m = $this->expectedMatch(100.0, 'eur', 25.0, 'EUR');
        self::assertNotNull($m);
        self::assertSame(100.0, $m['expected']); // shoda měny i přes velikost písmen → přímo
    }

    public function testMissingRateFallsBackToOneToOne(): void
    {
        // Chybějící kurz (0) → 1:1 fallback (lepší než dělit nulou / vůbec nepárovat).
        $m = $this->expectedMatch(500.0, 'EUR', 0.0, 'CZK');
        self::assertNotNull($m);
        self::assertSame(500.0, $m['expected']);
    }

    public function testFxToleranceHasAbsoluteFloor(): void
    {
        // Velmi malá částka: 4 % by bylo < 0.05, použije se absolutní floor (EXACT_MATCH_TOLERANCE).
        $m = $this->expectedMatch(0.50, 'EUR', 1.0, 'CZK'); // czk=0.5, 4 % = 0.02 < 0.05
        self::assertNotNull($m);
        self::assertSame(0.5, $m['expected']);
        self::assertSame(0.05, $m['exact']);
    }

    public function testFullFxSettlementRecordsWholeInvoiceRemainder(): void
    {
        // Banka připsala 24 300 CZK za plnou fakturu 1 000 EUR @ 24,50.
        // Rozdíl je kurz banky/spread; po přijetí jako exact musí být faktura vyrovnaná.
        self::assertSame(1000.0, $this->paymentAmount(24300.0, 'EUR', 24.5, 'CZK', 1000.0, true));
        // Efektivní bankovní kurz nemusí jít vyjádřit tak, aby násobení po
        // zaokrouhlení vrátilo přesně CZK pohyb; ani tehdy se hodnoty neslévají.
        self::assertSame(123.45, $this->paymentAmount(3018.41, 'EUR', 24.5, 'CZK', 123.45, true));
    }

    public function testPartialFxSettlementStillConvertsByInvoiceRate(): void
    {
        self::assertSame(408.16, $this->paymentAmount(10000.0, 'EUR', 24.5, 'CZK', 1000.0));
    }

    public function testSameCurrencyExactSettlementKeepsActualPaymentAmount(): void
    {
        // Nové pravidlo je pouze pro cross-currency; haléřová odchylka ve stejné
        // měně zůstává skutečnou evidovanou částkou.
        self::assertSame(999.98, $this->paymentAmount(999.98, 'EUR', 24.5, 'EUR', 1000.0, true));
    }
}
