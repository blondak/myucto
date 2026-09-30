<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\BankAmountSearch;
use PHPUnit\Framework\TestCase;

final class BankAmountSearchTest extends TestCase
{
    public function testNumericSearchKeepsDecimalPrecisionAndIgnoresDirection(): void
    {
        foreach (['1 234,50', '-1.234,50', '1,234.50', "1\u{00a0}234.50", '+1234.50'] as $value) {
            self::assertSame('1234.50', BankAmountSearch::normalize($value));
        }
        self::assertSame('1000', BankAmountSearch::normalize('1000'));
        foreach (['abc1234', '1234 Kč', '--1234', '12,3456', '1.2.3', ''] as $value) {
            self::assertNull(BankAmountSearch::normalize($value));
        }
    }
}
