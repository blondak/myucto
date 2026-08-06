<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use PHPUnit\Framework\TestCase;

/**
 * Poměrné rozdělení brutto platby mezi sazby DPH zálohové faktury (#89) —
 * largest-remainder na nejsilnější sazbě, součet podílů VŽDY přesně = platba
 * (jinak by daňový doklad k platbě nesouhlasil s přijatou úplatou na halíř).
 */
final class PaymentTaxDocumentAllocationTest extends TestCase
{
    public function testSingleRateGetsFullPayment(): void
    {
        $out = PaymentTaxDocumentCreator::allocateAcrossRates(
            [['rate' => 21.0, 'vat_rate_id' => 1, 'gross' => 12100.0]],
            5000.0,
        );
        self::assertCount(1, $out);
        self::assertSame(5000.0, $out[0]['amount']);
        self::assertSame(21.0, $out[0]['rate']);
    }

    public function testTwoRatesSplitProportionallyAndSumExactly(): void
    {
        // Proforma 12 100 (21 %) + 5 600 (12 %) = 17 700; platba 6 000.
        $out = PaymentTaxDocumentCreator::allocateAcrossRates(
            [
                ['rate' => 21.0, 'vat_rate_id' => 1, 'gross' => 12100.0],
                ['rate' => 12.0, 'vat_rate_id' => 2, 'gross' => 5600.0],
            ],
            6000.0,
        );
        self::assertSame(4101.69, $out[0]['amount'], '6000 × 12100/17700');
        self::assertSame(1898.31, $out[1]['amount'], '6000 × 5600/17700');
        self::assertSame(6000.0, round($out[0]['amount'] + $out[1]['amount'], 2), 'součet = platba na halíř');
    }

    public function testRoundingResidualGoesToLargestBucket(): void
    {
        // 100 / 3 stejné váhy → 33,33 + 33,33 + 33,33 = 99,99; reziduum 0,01 na první (nejsilnější).
        $out = PaymentTaxDocumentCreator::allocateAcrossRates(
            [
                ['rate' => 21.0, 'vat_rate_id' => 1, 'gross' => 100.0],
                ['rate' => 12.0, 'vat_rate_id' => 2, 'gross' => 100.0],
                ['rate' => 0.0,  'vat_rate_id' => 3, 'gross' => 100.0],
            ],
            100.0,
        );
        $sum = round(array_sum(array_column($out, 'amount')), 2);
        self::assertSame(100.0, $sum, 'reziduum dorovnáno, součet sedí');
        self::assertSame(33.34, $out[0]['amount'], 'reziduum na první (max gross) bucket');
    }

    public function testNegativeBucketFromDiscountLineAllocatesNegativeShare(): void
    {
        // Sleva v 0% sazbě: váhy 1210 / −10 (celkem 1200); platba 1200 → podíly 1210 / −10.
        $out = PaymentTaxDocumentCreator::allocateAcrossRates(
            [
                ['rate' => 21.0, 'vat_rate_id' => 1, 'gross' => 1210.0],
                ['rate' => 0.0,  'vat_rate_id' => 3, 'gross' => -10.0],
            ],
            1200.0,
        );
        self::assertSame(1210.0, $out[0]['amount']);
        self::assertSame(-10.0, $out[1]['amount']);
        self::assertSame(1200.0, round($out[0]['amount'] + $out[1]['amount'], 2));
    }

    /**
     * `carry` (zdrojová položka proformy, ze které se přenese OSS profil) musí zůstat
     * u SVÉHO kbelíku i poté, co alokátor vyhodí nulové váhy a přeindexuje. Kdyby si
     * volající pároval výstup se vstupem podle pozice, přiřadil by po vyhození nulového
     * kbelíku OSS profil sousednímu řádku — tedy přiznal daň v jiné zemi, tiše.
     */
    public function testCarryStaysWithItsBucketAcrossFilteringAndReindexing(): void
    {
        $out = PaymentTaxDocumentCreator::allocateAcrossRates(
            [
                ['rate' => 23.0, 'vat_rate_id' => 1, 'gross' => 0.0, 'carry' => ['tag' => 'vyhozeny']],
                ['rate' => 23.0, 'vat_rate_id' => 1, 'gross' => 1230.0, 'carry' => ['tag' => 'oss-pl']],
                ['rate' => 23.0, 'vat_rate_id' => 1, 'gross' => 1230.0, 'carry' => ['tag' => 'tuzemsko']],
            ],
            2460.0,
        );

        self::assertSame(['oss-pl', 'tuzemsko'], array_column(array_column($out, 'carry'), 'tag'));
    }

    /** Chybějící `carry` je prázdné pole, ne chybějící klíč — volající se nemá čeho chytat. */
    public function testCarryDefaultsToEmptyArray(): void
    {
        $out = PaymentTaxDocumentCreator::allocateAcrossRates(
            [['rate' => 21.0, 'vat_rate_id' => 1, 'gross' => 1210.0]],
            1210.0,
        );

        self::assertSame([], $out[0]['carry']);
    }

    public function testZeroBucketsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        PaymentTaxDocumentCreator::allocateAcrossRates([], 100.0);
    }

    public function testNonPositiveTotalRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        PaymentTaxDocumentCreator::allocateAcrossRates(
            [['rate' => 21.0, 'vat_rate_id' => 1, 'gross' => -500.0]],
            100.0,
        );
    }
}
